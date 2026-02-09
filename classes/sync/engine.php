<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_githubsync\sync;

use local_githubsync\github\client;

/**
 * Core sync orchestrator.
 *
 * Coordinates fetching repo content from GitHub and building/updating
 * the Moodle course structure to match.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var \stdClass Course record */
    private \stdClass $course;

    /** @var \stdClass Plugin config record from local_githubsync_config */
    private \stdClass $config;

    /** @var client GitHub API client */
    private client $github;

    /** @var course_builder Moodle course builder */
    private course_builder $builder;

    /** @var asset_handler|null Asset handler (created on demand) */
    private ?asset_handler $assets = null;

    /** @var array Running log of operations performed */
    private array $operations = [];

    /** @var int Sections created count */
    private int $sectionscreated = 0;

    /** @var int Sections updated count */
    private int $sectionsupdated = 0;

    /** @var int Activities created count */
    private int $activitiescreated = 0;

    /** @var int Activities updated count */
    private int $activitiesupdated = 0;

    /** @var int Activities hidden (removed from repo) count */
    private int $activitieshidden = 0;

    /** @var int Chapters created count */
    private int $chapterscreated = 0;

    /** @var int Chapters updated count */
    private int $chaptersupdated = 0;

    /** @var int Chapters hidden count */
    private int $chaptershidden = 0;

    /** @var int Assets uploaded count */
    private int $assetsuploaded = 0;

    /**
     * Constructor.
     *
     * @param \stdClass $course The course record
     * @param \stdClass $config The plugin config record
     */
    public function __construct(\stdClass $course, \stdClass $config) {
        $this->course = $course;
        $this->config = $config;

        $pat = self::decrypt_pat($config->pat_encrypted);
        $this->github = new client($config->repo_url, $pat, $config->branch);
        $this->builder = new course_builder($course);
    }

    /**
     * Execute the full sync process.
     *
     * @return array ['status' => string, 'sha' => string, 'summary' => string]
     * @throws \moodle_exception On failure
     */
    public function execute(): array {
        global $DB;

        // 1. Get latest commit SHA.
        $sha = $this->github->get_latest_commit_sha();

        // 2. Check if already up to date.
        if (!empty($this->config->last_sync_sha) && $sha === $this->config->last_sync_sha) {
            return [
                'status' => 'uptodate',
                'sha' => $sha,
                'summary' => get_string('syncuptodate', 'local_githubsync', substr($sha, 0, 7)),
            ];
        }

        // 3. Fetch full repo tree.
        $tree = $this->github->get_tree();

        // 4. Parse the tree into a structured representation.
        $repostructure = $this->parse_tree($tree);

        // 5. Process assets first (so URLs can be rewritten in HTML).
        if (!empty($repostructure['assets'])) {
            $this->assets = new asset_handler($this->course->id, $this->github);
            $assetresult = $this->assets->process_assets($repostructure['assets']);
            $this->assetsuploaded = $assetresult['uploaded'];
            $this->operations = array_merge($this->operations, $assetresult['operations']);
        }

        // 6. Process course.yaml if present.
        if (isset($repostructure['course_yaml'])) {
            $this->process_course_yaml($repostructure['course_yaml']);
        }

        // 7. Process sections and pages.
        $currentrepopaths = $this->process_sections($repostructure['sections']);

        // 8. Detect and handle removed files (hide activities no longer in repo).
        $this->handle_removed_files($currentrepopaths);

        // 9. Update config with new SHA and timestamp.
        $this->config->last_sync_sha = $sha;
        $this->config->last_sync_time = time();
        $this->config->timemodified = time();
        $DB->update_record('local_githubsync_config', $this->config);

        // 10. Build summary.
        $summary = $this->build_summary();

        return [
            'status' => 'success',
            'sha' => $sha,
            'summary' => $summary,
        ];
    }

    /**
     * Parse the GitHub tree into a structured representation.
     *
     * @param array $tree Raw tree from GitHub API
     * @return array Structured representation with 'course_yaml', 'sections', 'assets' keys
     */
    private function parse_tree(array $tree): array {
        $structure = [
            'course_yaml' => null,
            'sections' => [],
            'assets' => [],
        ];

        foreach ($tree as $item) {
            $path = $item['path'];

            // Course.yaml at root.
            if ($path === 'course.yaml' && $item['type'] === 'blob') {
                $structure['course_yaml'] = $path;
                continue;
            }

            // Asset files: assets/**/*.
            if (preg_match('#^assets/.+$#', $path) && $item['type'] === 'blob') {
                $structure['assets'][] = $path;
                continue;
            }

            // Section directories: sections/NN-name/.
            if (preg_match('#^sections/([^/]+)$#', $path, $matches) && $item['type'] === 'tree') {
                $dirname = $matches[1];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => [], 'books' => []];
                }
                continue;
            }

            // Section yaml: sections/NN-name/section.yaml.
            if (preg_match('#^sections/([^/]+)/section\.yaml$#', $path, $matches) && $item['type'] === 'blob') {
                $dirname = $matches[1];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => [], 'books' => []];
                }
                $structure['sections'][$dirname]['yaml'] = $path;
                continue;
            }

            // Book directories: sections/NN-name/NN-bookname/ (subdirectory = book).
            if (preg_match('#^sections/([^/]+)/([^/]+)$#', $path, $matches) && $item['type'] === 'tree') {
                $dirname = $matches[1];
                $bookdir = $matches[2];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => [], 'books' => []];
                }
                if (!isset($structure['sections'][$dirname]['books'][$bookdir])) {
                    $structure['sections'][$dirname]['books'][$bookdir] = ['yaml' => null, 'chapters' => []];
                }
                continue;
            }

            // Book yaml: sections/NN-name/NN-bookname/book.yaml.
            if (preg_match('#^sections/([^/]+)/([^/]+)/book\.yaml$#', $path, $matches) && $item['type'] === 'blob') {
                $dirname = $matches[1];
                $bookdir = $matches[2];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => [], 'books' => []];
                }
                if (!isset($structure['sections'][$dirname]['books'][$bookdir])) {
                    $structure['sections'][$dirname]['books'][$bookdir] = ['yaml' => null, 'chapters' => []];
                }
                $structure['sections'][$dirname]['books'][$bookdir]['yaml'] = $path;
                continue;
            }

            // Chapter files: sections/NN-name/NN-bookname/NN-chapter.html.
            if (
                preg_match('#^sections/([^/]+)/([^/]+)/([^/]+\.html)$#', $path, $matches)
                && $item['type'] === 'blob'
            ) {
                $dirname = $matches[1];
                $bookdir = $matches[2];
                $chapterfile = $matches[3];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => [], 'books' => []];
                }
                if (!isset($structure['sections'][$dirname]['books'][$bookdir])) {
                    $structure['sections'][$dirname]['books'][$bookdir] = ['yaml' => null, 'chapters' => []];
                }
                $structure['sections'][$dirname]['books'][$bookdir]['chapters'][$chapterfile] = $path;
                continue;
            }

            // HTML files in section (not nested in subdirectory): sections/NN-name/NN-page.html.
            if (preg_match('#^sections/([^/]+)/([^/]+\.html)$#', $path, $matches) && $item['type'] === 'blob') {
                $dirname = $matches[1];
                $filename = $matches[2];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => [], 'books' => []];
                }
                $structure['sections'][$dirname]['pages'][$filename] = $path;
                continue;
            }
        }

        // Sort sections by directory name (numeric prefix gives correct order).
        ksort($structure['sections']);

        // Sort pages and books within each section, and chapters within each book.
        foreach ($structure['sections'] as &$section) {
            ksort($section['pages']);
            ksort($section['books']);
            foreach ($section['books'] as &$book) {
                ksort($book['chapters']);
            }
        }

        return $structure;
    }

    /**
     * Process course.yaml — update course metadata.
     *
     * @param string $path Path to course.yaml in the repo
     */
    private function process_course_yaml(string $path): void {
        $content = $this->github->get_file_contents($path);
        $data = $this->parse_yaml($content);

        if (!empty($data)) {
            $this->builder->update_course_metadata($data);
            $this->log_operation('course_metadata', $path, 'updated');
        }
    }

    /**
     * Process all sections and their pages.
     *
     * @param array $sections Structured section data from parse_tree()
     * @return array List of all repo_paths that are currently in the repo (for delete detection)
     */
    private function process_sections(array $sections): array {
        global $DB;

        $currentpaths = [];
        $sectionnum = 0;

        foreach ($sections as $dirname => $sectiondata) {
            $sectionnum++;

            // Track this section path.
            $sectionpath = "sections/{$dirname}";
            $currentpaths[] = $sectionpath;

            // Parse section.yaml if present.
            $sectionmeta = [];
            if (!empty($sectiondata['yaml'])) {
                $yamlcontent = $this->github->get_file_contents($sectiondata['yaml']);
                $sectionmeta = $this->parse_yaml($yamlcontent);
            }

            // If no title in YAML, derive from directory name.
            if (empty($sectionmeta['title'])) {
                $sectionmeta['title'] = course_builder::derive_activity_name($dirname);
            }

            // Check if this section already exists via mapping.
            $existingmapping = $DB->get_record('local_githubsync_mapping', [
                'courseid' => $this->course->id,
                'repo_path' => $sectionpath,
            ]);

            $section = $this->builder->ensure_section($sectionnum, $sectionmeta);

            // Create or update mapping for the section.
            $this->upsert_mapping($sectionpath, null, $section->id, null);

            if ($existingmapping) {
                $this->sectionsupdated++;
            } else {
                $this->sectionscreated++;
            }

            // Process HTML files in this section.
            $pagepaths = $this->process_section_pages($sectionnum, $sectiondata['pages']);
            $currentpaths = array_merge($currentpaths, $pagepaths);

            // Process book directories in this section.
            if (!empty($sectiondata['books'])) {
                $bookpaths = $this->process_section_books($sectionnum, $dirname, $sectiondata['books']);
                $currentpaths = array_merge($currentpaths, $bookpaths);
            }
        }

        return $currentpaths;
    }

    /**
     * Process HTML page files within a section.
     *
     * @param int $sectionnum Section number
     * @param array $pages Associative array of filename => repo_path
     * @return array List of repo_paths processed
     */
    private function process_section_pages(int $sectionnum, array $pages): array {
        global $DB;

        $paths = [];

        foreach ($pages as $filename => $repopath) {
            $paths[] = $repopath;
            $activityname = course_builder::derive_activity_name($filename);
            $rawcontent = $this->github->get_file_contents($repopath);

            // Parse front matter if present.
            $parsed = course_builder::parse_front_matter($rawcontent);
            $frontmatter = $parsed['frontmatter'];
            $htmlcontent = $parsed['content'];

            // Rewrite asset URLs if asset handler is active.
            if ($this->assets) {
                $htmlcontent = $this->assets->rewrite_asset_urls($htmlcontent);
            }

            $contenthash = sha1($htmlcontent);

            // Check existing mapping.
            $mapping = $DB->get_record('local_githubsync_mapping', [
                'courseid' => $this->course->id,
                'repo_path' => $repopath,
            ]);

            if ($mapping && !empty($mapping->cmid)) {
                // Activity exists — check if content changed.
                if ($mapping->content_hash === $contenthash) {
                    $this->log_operation('page_skip', $repopath, 'unchanged');
                    continue;
                }

                // Content changed — update (page updates only for now).
                $acttype = $frontmatter['type'] ?? 'page';
                if ($acttype === 'page') {
                    $this->builder->update_page($mapping->cmid, $activityname, $htmlcontent);
                }
                $this->upsert_mapping($repopath, $mapping->cmid, null, $contenthash);
                $this->activitiesupdated++;
                $this->log_operation('page_update', $repopath, "updated cmid={$mapping->cmid}");
            } else {
                // New activity — create based on front matter type.
                $cmid = $this->builder->create_activity($sectionnum, $activityname, $htmlcontent, $frontmatter);
                $this->upsert_mapping($repopath, $cmid, null, $contenthash);
                $this->activitiescreated++;
                $acttype = $frontmatter['type'] ?? 'page';
                $this->log_operation("{$acttype}_create", $repopath, "created cmid={$cmid}");
            }
        }

        return $paths;
    }

    /**
     * Process book directories within a section.
     *
     * @param int $sectionnum Section number
     * @param string $sectiondirname Section directory name (e.g. "01-introduction")
     * @param array $books Associative array of bookdir => ['yaml' => path|null, 'chapters' => [filename => path]]
     * @return array List of all repo_paths tracked (book dir + yaml + chapter files)
     */
    private function process_section_books(int $sectionnum, string $sectiondirname, array $books): array {
        global $DB;

        $paths = [];

        foreach ($books as $bookdir => $bookdata) {
            $bookdirpath = "sections/{$sectiondirname}/{$bookdir}";
            $paths[] = $bookdirpath;

            if (!empty($bookdata['yaml'])) {
                $paths[] = $bookdata['yaml'];
            }

            // Add all chapter file paths.
            foreach ($bookdata['chapters'] as $chapterfile => $chapterpath) {
                $paths[] = $chapterpath;
            }

            // Check if this book already exists via mapping.
            $mapping = $DB->get_record('local_githubsync_mapping', [
                'courseid' => $this->course->id,
                'repo_path' => $bookdirpath,
            ]);

            if ($mapping && !empty($mapping->cmid)) {
                $this->update_existing_book($sectionnum, $bookdir, $bookdirpath, $bookdata, $mapping);
            } else {
                $this->create_new_book($sectionnum, $bookdir, $bookdirpath, $bookdata);
            }
        }

        return $paths;
    }

    /**
     * Create a new book activity from a book directory.
     *
     * @param int $sectionnum Section number
     * @param string $bookdir Book directory name
     * @param string $bookdirpath Full repo path to book directory
     * @param array $bookdata Book data: ['yaml' => path|null, 'chapters' => [filename => path]]
     */
    private function create_new_book(int $sectionnum, string $bookdir, string $bookdirpath, array $bookdata): void {
        // Parse book.yaml if present.
        $bookmeta = [];
        $yamlhash = null;
        if (!empty($bookdata['yaml'])) {
            $yamlcontent = $this->github->get_file_contents($bookdata['yaml']);
            $bookmeta = $this->parse_yaml($yamlcontent);
            $yamlhash = sha1($yamlcontent);
        }

        // Derive book name from directory if not in yaml.
        $bookname = !empty($bookmeta['title']) ? $bookmeta['title'] : course_builder::derive_activity_name($bookdir);

        // Fetch and parse all chapter files.
        $chapters = [];
        $chapterhashes = [];
        foreach ($bookdata['chapters'] as $chapterfile => $chapterpath) {
            $rawcontent = $this->github->get_file_contents($chapterpath);
            $parsed = course_builder::parse_front_matter($rawcontent);
            $frontmatter = $parsed['frontmatter'];
            $htmlcontent = $parsed['content'];

            // Rewrite asset URLs if asset handler is active.
            if ($this->assets) {
                $htmlcontent = $this->assets->rewrite_asset_urls($htmlcontent);
            }

            $chaptertitle = $frontmatter['title'] ?? course_builder::derive_activity_name($chapterfile);
            $subchapter = !empty($frontmatter['subchapter']);

            $chapters[] = [
                'title' => $chaptertitle,
                'content' => $htmlcontent,
                'subchapter' => $subchapter,
                'importsrc' => $chapterpath,
            ];
            $chapterhashes[$chapterpath] = sha1($htmlcontent);
        }

        if (empty($chapters)) {
            $this->log_operation('book_skip', $bookdirpath, 'no chapters found');
            return;
        }

        // Create the book activity with all chapters.
        $cmid = $this->builder->create_book($sectionnum, $bookname, $chapters, $bookmeta);

        // Create mapping for the book directory.
        $this->upsert_mapping($bookdirpath, $cmid, null, $yamlhash);

        // Create mapping rows for each chapter file.
        foreach ($chapterhashes as $chapterpath => $hash) {
            $this->upsert_mapping($chapterpath, $cmid, null, $hash);
        }

        // Create mapping for book.yaml if present.
        if (!empty($bookdata['yaml'])) {
            $this->upsert_mapping($bookdata['yaml'], $cmid, null, $yamlhash);
        }

        $this->activitiescreated++;
        $this->chapterscreated += count($chapters);
        $chaptercount = count($chapters);
        $this->log_operation('book_create', $bookdirpath, "created cmid={$cmid} with {$chaptercount} chapters");
    }

    /**
     * Update an existing book activity.
     *
     * @param int $sectionnum Section number
     * @param string $bookdir Book directory name
     * @param string $bookdirpath Full repo path to book directory
     * @param array $bookdata Book data: ['yaml' => path|null, 'chapters' => [filename => path]]
     * @param \stdClass $mapping Existing mapping record for the book directory
     */
    private function update_existing_book(
        int $sectionnum,
        string $bookdir,
        string $bookdirpath,
        array $bookdata,
        \stdClass $mapping
    ): void {
        global $DB;

        $cmid = (int) $mapping->cmid;
        $bookchanged = false;

        // Check book.yaml for metadata changes.
        if (!empty($bookdata['yaml'])) {
            $yamlcontent = $this->github->get_file_contents($bookdata['yaml']);
            $yamlhash = sha1($yamlcontent);

            // Check if yaml mapping exists and if hash changed.
            $yamlmapping = $DB->get_record('local_githubsync_mapping', [
                'courseid' => $this->course->id,
                'repo_path' => $bookdata['yaml'],
            ]);

            if (!$yamlmapping || $yamlmapping->content_hash !== $yamlhash) {
                $bookmeta = $this->parse_yaml($yamlcontent);
                $this->builder->update_book_metadata($cmid, $bookmeta);
                $this->upsert_mapping($bookdata['yaml'], $cmid, null, $yamlhash);
                $bookchanged = true;
                $this->log_operation('book_meta_update', $bookdata['yaml'], "updated book metadata cmid={$cmid}");
            }
        }

        // Get the book instance ID.
        $cm = get_coursemodule_from_id('book', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            $this->log_operation('book_error', $bookdirpath, "cmid={$cmid} not found, skipping");
            return;
        }
        $bookid = (int) $cm->instance;

        // Process each chapter file.
        $pagenum = 0;
        $currentimportsrcs = [];

        foreach ($bookdata['chapters'] as $chapterfile => $chapterpath) {
            $pagenum++;
            $currentimportsrcs[] = $chapterpath;

            $rawcontent = $this->github->get_file_contents($chapterpath);
            $parsed = course_builder::parse_front_matter($rawcontent);
            $frontmatter = $parsed['frontmatter'];
            $htmlcontent = $parsed['content'];

            if ($this->assets) {
                $htmlcontent = $this->assets->rewrite_asset_urls($htmlcontent);
            }

            $contenthash = sha1($htmlcontent);
            $chaptertitle = $frontmatter['title'] ?? course_builder::derive_activity_name($chapterfile);
            $subchapter = !empty($frontmatter['subchapter']) && $pagenum > 1;

            // Check existing chapter mapping.
            $chaptermapping = $DB->get_record('local_githubsync_mapping', [
                'courseid' => $this->course->id,
                'repo_path' => $chapterpath,
            ]);

            if ($chaptermapping && $chaptermapping->content_hash === $contenthash) {
                // Content unchanged — but still update pagenum in case ordering changed.
                $existingchapter = $DB->get_record(
                    'book_chapters',
                    ['bookid' => $bookid, 'importsrc' => $chapterpath]
                );
                if ($existingchapter && (int) $existingchapter->pagenum !== $pagenum) {
                    $existingchapter->pagenum = $pagenum;
                    $existingchapter->timemodified = time();
                    $DB->update_record('book_chapters', $existingchapter);
                    $bookchanged = true;
                }
                continue;
            }

            // Chapter content changed or is new.
            $updated = $this->builder->update_book_chapter(
                $bookid,
                $chapterpath,
                $chaptertitle,
                $htmlcontent,
                $subchapter,
                $pagenum
            );

            if ($updated) {
                $this->upsert_mapping($chapterpath, $cmid, null, $contenthash);
                $this->chaptersupdated++;
                $bookchanged = true;
                $this->log_operation('chapter_update', $chapterpath, "updated in book cmid={$cmid}");
            } else {
                // New chapter — create it.
                $this->builder->create_book_chapter(
                    $bookid,
                    $chaptertitle,
                    $htmlcontent,
                    $subchapter,
                    $pagenum,
                    $chapterpath
                );
                $this->upsert_mapping($chapterpath, $cmid, null, $contenthash);
                $this->chapterscreated++;
                $bookchanged = true;
                $this->log_operation('chapter_create', $chapterpath, "added to book cmid={$cmid}");
            }
        }

        // Hide chapters whose importsrc is no longer in the chapter list.
        $existingchapters = $DB->get_records('book_chapters', ['bookid' => $bookid]);
        foreach ($existingchapters as $chapter) {
            if (
                !empty($chapter->importsrc)
                && !in_array($chapter->importsrc, $currentimportsrcs)
                && !$chapter->hidden
            ) {
                $chapter->hidden = 1;
                $chapter->timemodified = time();
                $DB->update_record('book_chapters', $chapter);
                $this->chaptershidden++;
                $bookchanged = true;
                $this->log_operation('chapter_hide', $chapter->importsrc, "hidden in book cmid={$cmid}");
            }
        }

        // Bump book revision and preload chapters if anything changed.
        if ($bookchanged) {
            $book = $DB->get_record('book', ['id' => $bookid], '*', MUST_EXIST);
            $book->revision++;
            $book->timemodified = time();
            $DB->update_record('book', $book);
            book_preload_chapters($book);

            $this->activitiesupdated++;
            $this->log_operation('book_update', $bookdirpath, "updated book cmid={$cmid}");
        }
    }

    /**
     * Detect files that were removed from the repo and hide corresponding activities.
     *
     * @param array $currentpaths All repo paths currently in the repo
     */
    private function handle_removed_files(array $currentpaths): void {
        global $DB;

        // Get all mappings with cmids (i.e. page activities, not sections or assets).
        $mappings = $DB->get_records('local_githubsync_mapping', ['courseid' => $this->course->id]);

        foreach ($mappings as $mapping) {
            // Skip section mappings and asset mappings.
            if (empty($mapping->cmid)) {
                continue;
            }

            // Skip if still in repo.
            if (in_array($mapping->repo_path, $currentpaths)) {
                continue;
            }

            // Skip asset paths (handled separately).
            if (str_starts_with($mapping->repo_path, 'assets/')) {
                continue;
            }

            // Skip chapter-level mapping rows — these are managed inside update_existing_book().
            if (preg_match('#^sections/[^/]+/[^/]+/.+\.html$#', $mapping->repo_path)) {
                continue;
            }

            // This activity is no longer in the repo — hide it.
            try {
                $cm = get_coursemodule_from_id('', $mapping->cmid, 0, false, IGNORE_MISSING);
                if ($cm && $cm->visible) {
                    set_coursemodule_visible($mapping->cmid, 0);
                    $this->activitieshidden++;
                    $this->log_operation('page_hide', $mapping->repo_path, "hidden cmid={$mapping->cmid}");
                }
            } catch (\Exception $e) {
                $this->log_operation('page_hide_error', $mapping->repo_path, $e->getMessage());
            }
        }
    }

    /**
     * Create or update a mapping record.
     *
     * @param string $repopath Repo file path
     * @param int|null $cmid Course module ID
     * @param int|null $sectionid Section ID
     * @param string|null $contenthash Content hash
     */
    private function upsert_mapping(string $repopath, ?int $cmid, ?int $sectionid, ?string $contenthash): void {
        global $DB;

        $now = time();
        $existing = $DB->get_record('local_githubsync_mapping', [
            'courseid' => $this->course->id,
            'repo_path' => $repopath,
        ]);

        if ($existing) {
            $existing->timemodified = $now;
            if ($cmid !== null) {
                $existing->cmid = $cmid;
            }
            if ($sectionid !== null) {
                $existing->sectionid = $sectionid;
            }
            if ($contenthash !== null) {
                $existing->content_hash = $contenthash;
            }
            $DB->update_record('local_githubsync_mapping', $existing);
        } else {
            $record = new \stdClass();
            $record->courseid = $this->course->id;
            $record->repo_path = $repopath;
            $record->cmid = $cmid;
            $record->sectionid = $sectionid;
            $record->content_hash = $contenthash;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $DB->insert_record('local_githubsync_mapping', $record);
        }
    }

    /**
     * Log an operation to the operations array.
     */
    private function log_operation(string $type, string $path, string $detail): void {
        $this->operations[] = [
            'type' => $type,
            'path' => $path,
            'detail' => $detail,
            'time' => time(),
        ];
    }

    /**
     * Build a human-readable summary of the sync.
     *
     * @return string Summary text
     */
    private function build_summary(): string {
        $parts = [];

        if ($this->sectionscreated > 0) {
            $parts[] = get_string('sections_created', 'local_githubsync', $this->sectionscreated);
        }
        if ($this->sectionsupdated > 0) {
            $parts[] = get_string('sections_updated', 'local_githubsync', $this->sectionsupdated);
        }
        if ($this->activitiescreated > 0) {
            $parts[] = get_string('activities_created', 'local_githubsync', $this->activitiescreated);
        }
        if ($this->activitiesupdated > 0) {
            $parts[] = get_string('activities_updated', 'local_githubsync', $this->activitiesupdated);
        }
        if ($this->activitieshidden > 0) {
            $parts[] = get_string('activities_hidden', 'local_githubsync', $this->activitieshidden);
        }
        if ($this->chapterscreated > 0) {
            $parts[] = get_string('chapters_created', 'local_githubsync', $this->chapterscreated);
        }
        if ($this->chaptersupdated > 0) {
            $parts[] = get_string('chapters_updated', 'local_githubsync', $this->chaptersupdated);
        }
        if ($this->chaptershidden > 0) {
            $parts[] = get_string('chapters_hidden', 'local_githubsync', $this->chaptershidden);
        }
        if ($this->assetsuploaded > 0) {
            $parts[] = get_string('assets_uploaded', 'local_githubsync', $this->assetsuploaded);
        }

        if (empty($parts)) {
            return 'No changes needed.';
        }

        return implode(', ', $parts) . '.';
    }

    /**
     * Write a log entry for this sync operation.
     *
     * @param int $userid The user who triggered the sync
     * @param string $sha Commit SHA
     * @param string $status Status string
     * @param string $summary Summary text
     */
    public function write_log(int $userid, string $sha, string $status, string $summary): void {
        global $DB;

        $log = new \stdClass();
        $log->courseid = $this->course->id;
        $log->userid = $userid;
        $log->commit_sha = $sha;
        $log->status = $status;
        $log->summary = $summary;
        $log->details = json_encode($this->operations);
        $log->timecreated = time();

        $DB->insert_record('local_githubsync_log', $log);
    }

    /**
     * Parse YAML content. Uses Symfony YAML if available, otherwise a simple fallback parser.
     *
     * @param string $content YAML content
     * @return array Parsed key-value pairs
     */
    private function parse_yaml(string $content): array {
        if (empty(trim($content))) {
            return [];
        }

        // Try Symfony YAML if available.
        if (class_exists('\Symfony\Component\Yaml\Yaml')) {
            try {
                return \Symfony\Component\Yaml\Yaml::parse($content) ?? [];
            } catch (\Exception $e) {
                $this->log_operation('yaml_error', '', 'Symfony YAML parse failed: ' . $e->getMessage());
                // Fall through to simple parser.
            }
        }

        // Simple fallback YAML parser for basic key: value files.
        $result = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines, comments, and YAML document markers.
            if (empty($line) || $line[0] === '#' || $line === '---' || $line === '...') {
                continue;
            }

            // Match key: value (handling quoted values).
            if (preg_match('/^([a-zA-Z_]+)\s*:\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                // Remove surrounding quotes.
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                // Handle booleans.
                if ($value === 'true') {
                    $value = true;
                } else if ($value === 'false') {
                    $value = false;
                }

                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Encrypt a PAT for storage using Moodle's Sodium encryption API.
     *
     * @param string $pat The plaintext PAT
     * @return string The encrypted PAT
     * @throws \moodle_exception If encryption is not available
     */
    public static function encrypt_pat(string $pat): string {
        if (empty($pat)) {
            return '';
        }

        if (!class_exists('\core\encryption') || !\core\encryption::key_exists()) {
            throw new \moodle_exception(
                'syncfailed',
                'local_githubsync',
                '',
                get_string('encryption_required', 'local_githubsync')
            );
        }

        return \core\encryption::encrypt($pat);
    }

    /**
     * Decrypt PAT from storage.
     *
     * Handles migration from legacy base64-encoded values by re-encrypting
     * with Sodium on first access.
     *
     * @param string $encrypted The encrypted PAT
     * @return string The decrypted PAT
     * @throws \moodle_exception If decryption fails
     */
    public static function decrypt_pat(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }

        // Migrate legacy base64-only values (from older versions).
        if (str_starts_with($encrypted, 'base64:')) {
            $pat = base64_decode(substr($encrypted, 7));
            // Re-encrypt with Sodium if possible, so legacy values are upgraded.
            self::migrate_legacy_pat($pat, $encrypted);
            return $pat;
        }

        // Handle legacy plain base64 (no prefix, no colon — pre-encryption era).
        if (!str_contains($encrypted, ':')) {
            $pat = base64_decode($encrypted);
            self::migrate_legacy_pat($pat, $encrypted);
            return $pat;
        }

        // Standard Sodium decryption.
        if (!class_exists('\core\encryption')) {
            throw new \moodle_exception(
                'syncfailed',
                'local_githubsync',
                '',
                get_string('encryption_required', 'local_githubsync')
            );
        }

        return \core\encryption::decrypt($encrypted);
    }

    /**
     * Re-encrypt a legacy PAT with Sodium and update the database.
     *
     * @param string $plainpat The decrypted PAT
     * @param string $oldencrypted The old encrypted value to find the record
     */
    private static function migrate_legacy_pat(string $plainpat, string $oldencrypted): void {
        global $DB;

        if (!class_exists('\core\encryption') || !\core\encryption::key_exists()) {
            return; // Cannot migrate yet — will try again on next access.
        }

        try {
            $newencrypted = \core\encryption::encrypt($plainpat);
            $comparesql = $DB->sql_compare_text('pat_encrypted') . ' = ' . $DB->sql_compare_text(':pat');
            $records = $DB->get_records_select('local_githubsync_config', $comparesql, ['pat' => $oldencrypted]);
            foreach ($records as $record) {
                $record->pat_encrypted = $newencrypted;
                $record->timemodified = time();
                $DB->update_record('local_githubsync_config', $record);
            }
        } catch (\Exception $e) {
            // Silently fail migration — the PAT was already decrypted successfully.
            debugging('githubsync: Failed to migrate legacy PAT encryption: ' . $e->getMessage());
        }
    }
}
