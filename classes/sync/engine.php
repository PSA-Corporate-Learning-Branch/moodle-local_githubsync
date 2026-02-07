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

defined('MOODLE_INTERNAL') || die();

use local_githubsync\github\client;

/**
 * Core sync orchestrator.
 *
 * Coordinates fetching repo content from GitHub and building/updating
 * the Moodle course structure to match.
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
    private int $sections_created = 0;

    /** @var int Sections updated count */
    private int $sections_updated = 0;

    /** @var int Activities created count */
    private int $activities_created = 0;

    /** @var int Activities updated count */
    private int $activities_updated = 0;

    /** @var int Activities hidden (removed from repo) count */
    private int $activities_hidden = 0;

    /** @var int Assets uploaded count */
    private int $assets_uploaded = 0;

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
            $this->assets_uploaded = $assetresult['uploaded'];
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

            // course.yaml at root.
            if ($path === 'course.yaml' && $item['type'] === 'blob') {
                $structure['course_yaml'] = $path;
                continue;
            }

            // Asset files: assets/**/*
            if (preg_match('#^assets/.+$#', $path) && $item['type'] === 'blob') {
                $structure['assets'][] = $path;
                continue;
            }

            // Section directories: sections/NN-name/
            if (preg_match('#^sections/([^/]+)$#', $path, $matches) && $item['type'] === 'tree') {
                $dirname = $matches[1];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => []];
                }
                continue;
            }

            // Section yaml: sections/NN-name/section.yaml
            if (preg_match('#^sections/([^/]+)/section\.yaml$#', $path, $matches) && $item['type'] === 'blob') {
                $dirname = $matches[1];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => []];
                }
                $structure['sections'][$dirname]['yaml'] = $path;
                continue;
            }

            // HTML files: sections/NN-name/NN-page.html
            if (preg_match('#^sections/([^/]+)/(.+\.html)$#', $path, $matches) && $item['type'] === 'blob') {
                $dirname = $matches[1];
                $filename = $matches[2];
                if (!isset($structure['sections'][$dirname])) {
                    $structure['sections'][$dirname] = ['yaml' => null, 'pages' => []];
                }
                $structure['sections'][$dirname]['pages'][$filename] = $path;
                continue;
            }
        }

        // Sort sections by directory name (numeric prefix gives correct order).
        ksort($structure['sections']);

        // Sort pages within each section.
        foreach ($structure['sections'] as &$section) {
            ksort($section['pages']);
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
                $this->sections_updated++;
            } else {
                $this->sections_created++;
            }

            // Process HTML files in this section.
            $pagepaths = $this->process_section_pages($sectionnum, $sectiondata['pages']);
            $currentpaths = array_merge($currentpaths, $pagepaths);
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
                $this->activities_updated++;
                $this->log_operation('page_update', $repopath, "updated cmid={$mapping->cmid}");
            } else {
                // New activity — create based on front matter type.
                $cmid = $this->builder->create_activity($sectionnum, $activityname, $htmlcontent, $frontmatter);
                $this->upsert_mapping($repopath, $cmid, null, $contenthash);
                $this->activities_created++;
                $acttype = $frontmatter['type'] ?? 'page';
                $this->log_operation("{$acttype}_create", $repopath, "created cmid={$cmid}");
            }
        }

        return $paths;
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

            // This activity is no longer in the repo — hide it.
            try {
                $cm = get_coursemodule_from_id('', $mapping->cmid, 0, false, IGNORE_MISSING);
                if ($cm && $cm->visible) {
                    set_coursemodule_visible($mapping->cmid, 0);
                    $this->activities_hidden++;
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

        if ($this->sections_created > 0) {
            $parts[] = get_string('sections_created', 'local_githubsync', $this->sections_created);
        }
        if ($this->sections_updated > 0) {
            $parts[] = get_string('sections_updated', 'local_githubsync', $this->sections_updated);
        }
        if ($this->activities_created > 0) {
            $parts[] = get_string('activities_created', 'local_githubsync', $this->activities_created);
        }
        if ($this->activities_updated > 0) {
            $parts[] = get_string('activities_updated', 'local_githubsync', $this->activities_updated);
        }
        if ($this->activities_hidden > 0) {
            $parts[] = get_string('activities_hidden', 'local_githubsync', $this->activities_hidden);
        }
        if ($this->assets_uploaded > 0) {
            $parts[] = get_string('assets_uploaded', 'local_githubsync', $this->assets_uploaded);
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
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                // Handle booleans.
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                }

                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Encrypt a PAT for storage using Moodle's encryption API.
     *
     * @param string $pat The plaintext PAT
     * @return string The encrypted PAT
     */
    public static function encrypt_pat(string $pat): string {
        if (empty($pat)) {
            return '';
        }

        // Use Moodle's encryption API if available and key exists.
        if (class_exists('\core\encryption') && \core\encryption::key_exists()) {
            return \core\encryption::encrypt($pat);
        }

        // Fallback to base64 if encryption key not set up.
        return 'base64:' . base64_encode($pat);
    }

    /**
     * Decrypt PAT from storage.
     *
     * @param string $encrypted The encrypted PAT
     * @return string The decrypted PAT
     */
    public static function decrypt_pat(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }

        // Handle base64 fallback format.
        if (str_starts_with($encrypted, 'base64:')) {
            return base64_decode(substr($encrypted, 7));
        }

        // Handle legacy plain base64 (from Phase 1).
        if (!str_contains($encrypted, ':')) {
            return base64_decode($encrypted);
        }

        // Use Moodle's encryption API.
        if (class_exists('\core\encryption')) {
            return \core\encryption::decrypt($encrypted);
        }

        // Last resort fallback.
        return base64_decode($encrypted);
    }
}
