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

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/lib/resourcelib.php');
require_once($CFG->dirroot . '/mod/book/locallib.php');
require_once($CFG->dirroot . '/mod/lesson/lib.php');
require_once($CFG->dirroot . '/mod/lesson/locallib.php');
require_once($CFG->dirroot . '/mod/lesson/pagetypes/branchtable.php');
require_once($CFG->dirroot . '/mod/lesson/pagetypes/truefalse.php');
require_once($CFG->dirroot . '/mod/lesson/pagetypes/multichoice.php');

/**
 * Handles creating and updating Moodle course structure from repo data.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_builder {
    /** @var \stdClass The course object */
    private \stdClass $course;

    /** @var array Module IDs from the modules table, keyed by module name */
    private array $moduleids = [];

    /**
     * Constructor.
     *
     * @param \stdClass $course The course record
     */
    public function __construct(\stdClass $course) {
        global $DB;
        $this->course = $course;

        // Cache module IDs for the types we support.
        foreach (['page', 'label', 'url', 'book', 'lesson'] as $modname) {
            $id = $DB->get_field('modules', 'id', ['name' => $modname]);
            if ($id) {
                $this->moduleids[$modname] = (int) $id;
            }
        }
    }

    /**
     * Update the course metadata from course.yaml data.
     *
     * @param array $coursedata Parsed course.yaml contents
     */
    public function update_course_metadata(array $coursedata): void {
        global $DB;

        $update = new \stdClass();
        $update->id = $this->course->id;
        $changed = false;

        if (!empty($coursedata['fullname']) && $coursedata['fullname'] !== $this->course->fullname) {
            $update->fullname = $coursedata['fullname'];
            $changed = true;
        }
        if (!empty($coursedata['shortname']) && $coursedata['shortname'] !== $this->course->shortname) {
            // Validate shortname is not already taken by another course.
            $existing = $DB->get_record('course', ['shortname' => $coursedata['shortname']]);
            if (!$existing || $existing->id == $this->course->id) {
                $update->shortname = clean_param($coursedata['shortname'], PARAM_TEXT);
                $changed = true;
            }
        }
        if (isset($coursedata['summary']) && $coursedata['summary'] !== $this->course->summary) {
            $update->summary = purify_html($coursedata['summary']);
            $update->summaryformat = FORMAT_HTML;
            $changed = true;
        }
        if (!empty($coursedata['format']) && $coursedata['format'] !== $this->course->format) {
            // Validate format is an installed course format.
            $formats = \core_plugin_manager::instance()->get_plugins_of_type('format');
            if (isset($formats[$coursedata['format']])) {
                $update->format = $coursedata['format'];
                $changed = true;
            }
        }

        if ($changed) {
            $update->timemodified = time();
            $DB->update_record('course', $update);
            // Refresh our cached course object.
            $this->course = get_course($this->course->id);
        }
    }

    /**
     * Create or update a course section.
     *
     * @param int $sectionnum Section number (position)
     * @param array $sectiondata Parsed section.yaml contents
     * @return \stdClass The course section record
     */
    public function ensure_section(int $sectionnum, array $sectiondata): \stdClass {
        global $DB;

        // Get existing section or create new one.
        $section = $DB->get_record('course_sections', [
            'course' => $this->course->id,
            'section' => $sectionnum,
        ]);

        if (!$section) {
            course_create_section($this->course->id, $sectionnum);
            $section = $DB->get_record('course_sections', [
                'course' => $this->course->id,
                'section' => $sectionnum,
            ], '*', MUST_EXIST);
        }

        // Update section name and summary if provided.
        $updatedata = [];
        if (!empty($sectiondata['title'])) {
            $updatedata['name'] = $sectiondata['title'];
        }
        if (isset($sectiondata['summary'])) {
            $updatedata['summary'] = purify_html($sectiondata['summary']);
            $updatedata['summaryformat'] = FORMAT_HTML;
        }
        if (isset($sectiondata['visible'])) {
            $updatedata['visible'] = $sectiondata['visible'] ? 1 : 0;
        }

        if (!empty($updatedata)) {
            course_update_section($this->course->id, $section, $updatedata);
            // Refresh.
            $section = $DB->get_record('course_sections', [
                'course' => $this->course->id,
                'section' => $sectionnum,
            ], '*', MUST_EXIST);
        }

        return $section;
    }

    /**
     * Create a new Page activity in a section.
     *
     * @param int $sectionnum Section number
     * @param string $name Activity name
     * @param string $htmlcontent HTML content for the page
     * @return int The course module ID (cmid)
     */
    public function create_page(int $sectionnum, string $name, string $htmlcontent): int {
        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'page';
        $moduleinfo->module = $this->moduleids['page'];
        $moduleinfo->name = $name;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;

        // Page-specific fields — sanitize HTML from external source.
        $moduleinfo->content = purify_html($htmlcontent);
        $moduleinfo->contentformat = FORMAT_HTML;
        $moduleinfo->display = \RESOURCELIB_DISPLAY_OPEN;
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 0;

        // Set intro directly (not via introeditor) to avoid draft area file handling
        // which requires a valid user context.
        $moduleinfo->intro = '';
        $moduleinfo->introformat = FORMAT_HTML;

        $result = add_moduleinfo($moduleinfo, $this->course);

        return $result->coursemodule;
    }

    /**
     * Update an existing Page activity's content.
     *
     * @param int $cmid Course module ID
     * @param string $name Activity name
     * @param string $htmlcontent New HTML content
     */
    public function update_page(int $cmid, string $name, string $htmlcontent): void {
        global $DB;

        $cm = get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
        $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

        // Update the page record directly for content changes — sanitize HTML from external source.
        $page->name = $name;
        $page->content = purify_html($htmlcontent);
        $page->contentformat = FORMAT_HTML;
        $page->timemodified = time();
        $DB->update_record('page', $page);

        // Rebuild course cache to reflect changes.
        rebuild_course_cache($this->course->id, true);
    }

    /**
     * Create a Label activity in a section.
     *
     * @param int $sectionnum Section number
     * @param string $name Activity name
     * @param string $htmlcontent HTML content for the label
     * @return int The course module ID (cmid)
     */
    public function create_label(int $sectionnum, string $name, string $htmlcontent): int {
        if (empty($this->moduleids['label'])) {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'Label module not available');
        }

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'label';
        $moduleinfo->module = $this->moduleids['label'];
        $moduleinfo->name = $name;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;

        // Label uses intro as its content — sanitize HTML from external source.
        $moduleinfo->intro = purify_html($htmlcontent);
        $moduleinfo->introformat = FORMAT_HTML;

        $result = add_moduleinfo($moduleinfo, $this->course);

        return $result->coursemodule;
    }

    /**
     * Create a URL activity in a section.
     *
     * @param int $sectionnum Section number
     * @param string $name Activity name
     * @param string $url The external URL
     * @param string $intro Optional description
     * @return int The course module ID (cmid)
     */
    public function create_url(int $sectionnum, string $name, string $url, string $intro = ''): int {
        if (empty($this->moduleids['url'])) {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'URL module not available');
        }

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'url';
        $moduleinfo->module = $this->moduleids['url'];
        $moduleinfo->name = $name;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;

        $moduleinfo->externalurl = $url;
        $moduleinfo->display = \RESOURCELIB_DISPLAY_AUTO;

        $moduleinfo->intro = purify_html($intro);
        $moduleinfo->introformat = FORMAT_HTML;

        $result = add_moduleinfo($moduleinfo, $this->course);

        return $result->coursemodule;
    }

    /**
     * Create a Book activity with chapters.
     *
     * @param int $sectionnum Section number
     * @param string $name Book name
     * @param array $chapters Ordered array of chapter data: ['title', 'content', 'subchapter', 'importsrc']
     * @param array $bookmeta Optional book metadata from book.yaml (numbering, intro)
     * @return int The course module ID (cmid)
     */
    public function create_book(int $sectionnum, string $name, array $chapters, array $bookmeta = []): int {
        global $DB;

        if (empty($this->moduleids['book'])) {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'Book module not available');
        }

        // Map numbering string values to book constants.
        $numberingmap = ['none' => 0, 'numbers' => 1, 'bullets' => 2, 'indented' => 3];
        $numbering = 0;
        if (!empty($bookmeta['numbering']) && isset($numberingmap[$bookmeta['numbering']])) {
            $numbering = $numberingmap[$bookmeta['numbering']];
        }

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'book';
        $moduleinfo->module = $this->moduleids['book'];
        $moduleinfo->name = !empty($bookmeta['title']) ? $bookmeta['title'] : $name;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;

        $moduleinfo->intro = purify_html($bookmeta['intro'] ?? '');
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->numbering = $numbering;
        $moduleinfo->navstyle = 1;
        $moduleinfo->customtitles = 0;

        $result = add_moduleinfo($moduleinfo, $this->course);
        $cmid = $result->coursemodule;

        // Get the book instance ID.
        $cm = get_coursemodule_from_id('book', $cmid, 0, false, MUST_EXIST);
        $bookid = $cm->instance;

        // Insert chapters.
        $pagenum = 0;
        foreach ($chapters as $chapter) {
            $pagenum++;
            $rec = new \stdClass();
            $rec->bookid = $bookid;
            $rec->pagenum = $pagenum;
            $rec->subchapter = ($pagenum === 1) ? 0 : (!empty($chapter['subchapter']) ? 1 : 0);
            $rec->title = $chapter['title'];
            $rec->content = purify_html($chapter['content']);
            $rec->contentformat = FORMAT_HTML;
            $rec->hidden = 0;
            $rec->importsrc = $chapter['importsrc'] ?? '';
            $rec->timecreated = time();
            $rec->timemodified = time();
            $DB->insert_record('book_chapters', $rec);
        }

        // Preload chapters to validate structure.
        $book = $DB->get_record('book', ['id' => $bookid], '*', MUST_EXIST);
        book_preload_chapters($book);

        return $cmid;
    }

    /**
     * Update book metadata (name, numbering, intro) from book.yaml.
     *
     * @param int $cmid Course module ID of the book
     * @param array $bookmeta Parsed book.yaml data
     */
    public function update_book_metadata(int $cmid, array $bookmeta): void {
        global $DB;

        $cm = get_coursemodule_from_id('book', $cmid, 0, false, MUST_EXIST);
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);

        $numberingmap = ['none' => 0, 'numbers' => 1, 'bullets' => 2, 'indented' => 3];
        $changed = false;

        if (!empty($bookmeta['title']) && $bookmeta['title'] !== $book->name) {
            $book->name = $bookmeta['title'];
            $changed = true;
        }
        if (!empty($bookmeta['numbering']) && isset($numberingmap[$bookmeta['numbering']])) {
            $newnumbering = $numberingmap[$bookmeta['numbering']];
            if ($newnumbering !== (int) $book->numbering) {
                $book->numbering = $newnumbering;
                $changed = true;
            }
        }
        if (isset($bookmeta['intro'])) {
            if (purify_html($bookmeta['intro']) !== $book->intro) {
                $book->intro = purify_html($bookmeta['intro']);
                $book->introformat = FORMAT_HTML;
                $changed = true;
            }
        }

        if ($changed) {
            $book->timemodified = time();
            $DB->update_record('book', $book);
            rebuild_course_cache($this->course->id, true);
        }
    }

    /**
     * Update an existing book chapter found by importsrc.
     *
     * @param int $bookid Book instance ID
     * @param string $importsrc The repo path stored in importsrc
     * @param string $title Chapter title
     * @param string $content Chapter HTML content
     * @param bool $subchapter Whether this is a subchapter
     * @param int $pagenum New page number
     * @return bool True if found and updated
     */
    public function update_book_chapter(
        int $bookid,
        string $importsrc,
        string $title,
        string $content,
        bool $subchapter,
        int $pagenum
    ): bool {
        global $DB;

        $chapter = $DB->get_record('book_chapters', ['bookid' => $bookid, 'importsrc' => $importsrc]);
        if (!$chapter) {
            return false;
        }

        $chapter->title = $title;
        $chapter->content = purify_html($content);
        $chapter->contentformat = FORMAT_HTML;
        $chapter->subchapter = $subchapter ? 1 : 0;
        $chapter->pagenum = $pagenum;
        $chapter->hidden = 0;
        $chapter->timemodified = time();
        $DB->update_record('book_chapters', $chapter);

        return true;
    }

    /**
     * Create a new chapter in an existing book.
     *
     * @param int $bookid Book instance ID
     * @param string $title Chapter title
     * @param string $content Chapter HTML content
     * @param bool $subchapter Whether this is a subchapter
     * @param int $pagenum Page number for ordering
     * @param string $importsrc Repo path for tracking
     */
    public function create_book_chapter(
        int $bookid,
        string $title,
        string $content,
        bool $subchapter,
        int $pagenum,
        string $importsrc
    ): void {
        global $DB;

        $rec = new \stdClass();
        $rec->bookid = $bookid;
        $rec->pagenum = $pagenum;
        $rec->subchapter = $subchapter ? 1 : 0;
        $rec->title = $title;
        $rec->content = purify_html($content);
        $rec->contentformat = FORMAT_HTML;
        $rec->hidden = 0;
        $rec->importsrc = $importsrc;
        $rec->timecreated = time();
        $rec->timemodified = time();
        $DB->insert_record('book_chapters', $rec);
    }

    /**
     * Create an activity based on front matter type.
     *
     * @param int $sectionnum Section number
     * @param string $name Activity name
     * @param string $htmlcontent HTML content (after front matter removed)
     * @param array $frontmatter Parsed front matter
     * @return int The course module ID (cmid)
     */
    public function create_activity(int $sectionnum, string $name, string $htmlcontent, array $frontmatter): int {
        $type = $frontmatter['type'] ?? 'page';

        // Override name from front matter if provided.
        if (!empty($frontmatter['name'])) {
            $name = $frontmatter['name'];
        }

        $visible = $frontmatter['visible'] ?? true;

        switch ($type) {
            case 'label':
                return $this->create_label($sectionnum, $name, $htmlcontent);

            case 'url':
                $url = $frontmatter['url'] ?? '';
                if (empty($url)) {
                    throw new \moodle_exception(
                        'syncfailed',
                        'local_githubsync',
                        '',
                        "URL activity requires 'url' in front matter"
                    );
                }
                return $this->create_url($sectionnum, $name, $url, $htmlcontent);

            case 'page':
            default:
                return $this->create_page($sectionnum, $name, $htmlcontent);
        }
    }

    /**
     * Parse YAML front matter from HTML content.
     *
     * Front matter is a YAML block delimited by --- at the start of the file:
     *   ---
     *   type: label
     *   visible: true
     *   ---
     *   <p>HTML content here</p>
     *
     * @param string $content Raw file content
     * @return array ['frontmatter' => array, 'content' => string]
     */
    public static function parse_front_matter(string $content): array {
        // Check for front matter delimiter at start of content.
        if (!preg_match('/\A---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return ['frontmatter' => [], 'content' => $content];
        }

        $yamlblock = $matches[1];
        $htmlcontent = substr($content, strlen($matches[0]));

        // Parse the YAML block (simple key: value pairs).
        $frontmatter = [];
        $lines = explode("\n", $yamlblock);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^([a-zA-Z_]+)\s*:\s*(.*)$/', $line, $m)) {
                $key = trim($m[1]);
                $value = trim($m[2]);

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

                $frontmatter[$key] = $value;
            }
        }

        return ['frontmatter' => $frontmatter, 'content' => $htmlcontent];
    }

    /**
     * Derive an activity name from a filename.
     *
     * Strips numeric prefix and extension, replaces hyphens with spaces, title-cases.
     * E.g. "01-welcome.html" -> "Welcome"
     * E.g. "02-interactive-lesson.html" -> "Interactive Lesson"
     *
     * @param string $filename The filename
     * @return string The derived activity name
     */
    public static function derive_activity_name(string $filename): string {
        // Remove extension.
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Strip leading numeric prefix (e.g. "01-", "02-").
        $name = preg_replace('/^\d+-/', '', $name);

        // Replace hyphens and underscores with spaces.
        $name = str_replace(['-', '_'], ' ', $name);

        // Title case.
        $name = ucwords(trim($name));

        return $name;
    }

    /**
     * Parse YAML front matter from HTML content, supporting nested lists.
     *
     * Extends the basic parse_front_matter() to handle YAML lists for
     * multichoice answers. Uses a state machine approach.
     *
     * @param string $content Raw file content
     * @return array ['frontmatter' => array, 'content' => string]
     */
    public static function parse_lesson_front_matter(string $content): array {
        if (!preg_match('/\A---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return ['frontmatter' => [], 'content' => $content];
        }

        $yamlblock = $matches[1];
        $htmlcontent = substr($content, strlen($matches[0]));

        $frontmatter = [];
        $lines = explode("\n", $yamlblock);

        // State machine: 'top' = flat key:value, 'list' = collecting list items.
        $state = 'top';
        $listkey = '';
        $currentitem = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            // Check if this is a list item: "  - key: value" or "  - text".
            if (preg_match('/^\s+-\s+(.+)$/', $line, $m)) {
                if ($state === 'list') {
                    // If we have a pending item, save it.
                    if ($currentitem !== null) {
                        $frontmatter[$listkey][] = $currentitem;
                    }
                    // Check if this is "- key: value" (start of dict item).
                    if (preg_match('/^([a-zA-Z_]+)\s*:\s*(.*)$/', trim($m[1]), $kv)) {
                        $currentitem = [$kv[1] => self::parse_yaml_value(trim($kv[2]))];
                    } else {
                        // Simple list value.
                        $currentitem = self::parse_yaml_value(trim($m[1]));
                    }
                }
                continue;
            }

            // Check if this is a continuation of a dict item: "    key: value" (indented, no dash).
            if ($state === 'list' && preg_match('/^\s{4,}([a-zA-Z_]+)\s*:\s*(.*)$/', $line, $kv)) {
                if (is_array($currentitem)) {
                    $currentitem[$kv[1]] = self::parse_yaml_value(trim($kv[2]));
                }
                continue;
            }

            // Top-level key: value.
            if (preg_match('/^([a-zA-Z_]+)\s*:\s*(.*)$/', $trimmed, $m)) {
                // Flush any pending list.
                if ($state === 'list' && $currentitem !== null) {
                    $frontmatter[$listkey][] = $currentitem;
                    $currentitem = null;
                }

                $key = $m[1];
                $value = trim($m[2]);

                if ($value === '') {
                    // Empty value = start of a list.
                    $state = 'list';
                    $listkey = $key;
                    $frontmatter[$key] = [];
                    $currentitem = null;
                } else {
                    $state = 'top';
                    $frontmatter[$key] = self::parse_yaml_value($value);
                }
            }
        }

        // Flush any pending list item.
        if ($state === 'list' && $currentitem !== null) {
            $frontmatter[$listkey][] = $currentitem;
        }

        return ['frontmatter' => $frontmatter, 'content' => $htmlcontent];
    }

    /**
     * Parse a single YAML value: handle booleans, numerics, and quoted strings.
     *
     * @param string $value Raw value string
     * @return mixed Parsed value
     */
    private static function parse_yaml_value(string $value) {
        // Remove surrounding quotes.
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        // Booleans.
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // Integers.
        if (ctype_digit($value)) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Create a Lesson activity with pages.
     *
     * @param int $sectionnum Section number
     * @param string $name Lesson name
     * @param array $pages Ordered array of page data: ['title', 'content', 'pagetype', 'pagedata']
     * @param array $lessonmeta Optional lesson metadata from lesson.yaml
     * @return array ['cmid' => int, 'pagemap' => [repo_path => lesson_pages_id]]
     */
    public function create_lesson(int $sectionnum, string $name, array $pages, array $lessonmeta = []): array {
        global $DB, $PAGE;

        if (empty($this->moduleids['lesson'])) {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'Lesson module not available');
        }

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'lesson';
        $moduleinfo->module = $this->moduleids['lesson'];
        $moduleinfo->name = !empty($lessonmeta['title']) ? $lessonmeta['title'] : $name;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;

        $moduleinfo->intro = purify_html($lessonmeta['intro'] ?? '');
        $moduleinfo->introformat = FORMAT_HTML;

        // Lesson-specific settings.
        $moduleinfo->practice = !empty($lessonmeta['practice']) ? 1 : 0;
        $moduleinfo->modattempts = !empty($lessonmeta['retake']) ? 1 : 0;
        $moduleinfo->feedback = isset($lessonmeta['feedback']) ? ($lessonmeta['feedback'] ? 1 : 0) : 1;
        $moduleinfo->review = !empty($lessonmeta['review']) ? 1 : 0;
        $moduleinfo->maxattempts = $lessonmeta['maxattempts'] ?? 1;
        $moduleinfo->retake = isset($lessonmeta['retake']) ? ($lessonmeta['retake'] ? 1 : 0) : 1;
        $moduleinfo->progressbar = isset($lessonmeta['progressbar']) ? ($lessonmeta['progressbar'] ? 1 : 0) : 1;
        $moduleinfo->maxanswers = 5;
        $moduleinfo->grade = 100;
        $moduleinfo->mediafile = 0;
        $moduleinfo->available = 0;
        $moduleinfo->deadline = 0;
        $moduleinfo->usepassword = 0;
        $moduleinfo->password = '';

        $result = add_moduleinfo($moduleinfo, $this->course);
        $cmid = $result->coursemodule;

        // Load lesson object for page creation.
        $cm = get_coursemodule_from_id('lesson', $cmid, 0, false, MUST_EXIST);
        $lesson = new \lesson($DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST));
        $context = \context_module::instance($cmid);

        // Set $PAGE->set_course() — needed in CLI context for file handling.
        $PAGE->set_course($this->course);

        // Insert pages in order, chaining prevpageid.
        $prevpageid = 0;
        $pagemap = [];
        $pagecount = count($pages);
        $pageindex = 0;

        foreach ($pages as $pagedata) {
            $pageindex++;
            $islast = ($pageindex === $pagecount);

            $properties = new \stdClass();
            $properties->title = $pagedata['title'];
            $properties->pageid = $prevpageid; // Insert after this page.
            $properties->contents_editor = [
                'text' => purify_html($pagedata['content']),
                'format' => FORMAT_HTML,
            ];

            $pagetype = $pagedata['pagetype'] ?? 'content';

            switch ($pagetype) {
                case 'truefalse':
                    $properties->qtype = \LESSON_PAGE_TRUEFALSE;
                    $this->setup_truefalse_answers($properties, $pagedata['pagedata'] ?? []);
                    break;

                case 'multichoice':
                    $properties->qtype = \LESSON_PAGE_MULTICHOICE;
                    $properties->qoption = 0; // Single answer.
                    $this->setup_multichoice_answers($properties, $pagedata['pagedata'] ?? []);
                    break;

                case 'content':
                default:
                    $properties->qtype = \LESSON_PAGE_BRANCHTABLE;
                    $properties->layout = 1;
                    $properties->display = 1;
                    // Content page: single "Continue" button.
                    $properties->answer_editor = [];
                    $properties->jumpto = [];
                    $properties->answer_editor[0] = 'Continue';
                    $properties->jumpto[0] = $islast ? \LESSON_EOL : \LESSON_NEXTPAGE;
                    break;
            }

            $newpage = \lesson_page::create($properties, $lesson, $context, $this->course->maxbytes);
            $prevpageid = $newpage->id;

            if (!empty($pagedata['repo_path'])) {
                $pagemap[$pagedata['repo_path']] = $newpage->id;
            }
        }

        return ['cmid' => $cmid, 'pagemap' => $pagemap];
    }

    /**
     * Set up true/false answer properties for lesson page creation.
     *
     * @param \stdClass $properties Page properties to modify
     * @param array $data Front matter data (correct, feedback_correct, feedback_incorrect)
     */
    private function setup_truefalse_answers(\stdClass $properties, array $data): void {
        $correctistrue = $data['correct'] ?? true;

        $properties->answer_editor = [];
        $properties->response_editor = [];
        $properties->jumpto = [];
        $properties->score = [];

        // Answer 0: True.
        $properties->answer_editor[0] = ['text' => 'True', 'format' => FORMAT_HTML];
        $properties->response_editor[0] = [
            'text' => $correctistrue ? ($data['feedback_correct'] ?? '') : ($data['feedback_incorrect'] ?? ''),
            'format' => FORMAT_HTML,
        ];
        $properties->jumpto[0] = $correctistrue ? \LESSON_NEXTPAGE : \LESSON_THISPAGE;
        $properties->score[0] = $correctistrue ? 1 : 0;

        // Answer 1: False.
        $properties->answer_editor[1] = ['text' => 'False', 'format' => FORMAT_HTML];
        $properties->response_editor[1] = [
            'text' => $correctistrue ? ($data['feedback_incorrect'] ?? '') : ($data['feedback_correct'] ?? ''),
            'format' => FORMAT_HTML,
        ];
        $properties->jumpto[1] = $correctistrue ? \LESSON_THISPAGE : \LESSON_NEXTPAGE;
        $properties->score[1] = $correctistrue ? 0 : 1;
    }

    /**
     * Set up multichoice answer properties for lesson page creation.
     *
     * @param \stdClass $properties Page properties to modify
     * @param array $data Front matter data with 'answers' array
     */
    private function setup_multichoice_answers(\stdClass $properties, array $data): void {
        $answers = $data['answers'] ?? [];

        $properties->answer_editor = [];
        $properties->response_editor = [];
        $properties->jumpto = [];
        $properties->score = [];

        foreach ($answers as $i => $answer) {
            $iscorrect = !empty($answer['correct']);
            $properties->answer_editor[$i] = [
                'text' => $answer['text'] ?? '',
                'format' => FORMAT_HTML,
            ];
            $properties->response_editor[$i] = [
                'text' => $answer['feedback'] ?? '',
                'format' => FORMAT_HTML,
            ];
            $properties->jumpto[$i] = $iscorrect ? \LESSON_NEXTPAGE : \LESSON_THISPAGE;
            $properties->score[$i] = $iscorrect ? 1 : 0;
        }
    }

    /**
     * Update lesson metadata (name, intro, settings) from lesson.yaml.
     *
     * @param int $cmid Course module ID of the lesson
     * @param array $lessonmeta Parsed lesson.yaml data
     */
    public function update_lesson_metadata(int $cmid, array $lessonmeta): void {
        global $DB;

        $cm = get_coursemodule_from_id('lesson', $cmid, 0, false, MUST_EXIST);
        $lesson = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);

        $changed = false;

        if (!empty($lessonmeta['title']) && $lessonmeta['title'] !== $lesson->name) {
            $lesson->name = $lessonmeta['title'];
            $changed = true;
        }
        if (isset($lessonmeta['intro'])) {
            $newintra = purify_html($lessonmeta['intro']);
            if ($newintra !== $lesson->intro) {
                $lesson->intro = $newintra;
                $lesson->introformat = FORMAT_HTML;
                $changed = true;
            }
        }
        if (isset($lessonmeta['practice']) && (int) !empty($lessonmeta['practice']) !== (int) $lesson->practice) {
            $lesson->practice = !empty($lessonmeta['practice']) ? 1 : 0;
            $changed = true;
        }
        if (isset($lessonmeta['retake']) && ($lessonmeta['retake'] ? 1 : 0) !== (int) $lesson->retake) {
            $lesson->retake = $lessonmeta['retake'] ? 1 : 0;
            $changed = true;
        }
        if (isset($lessonmeta['feedback']) && ($lessonmeta['feedback'] ? 1 : 0) !== (int) $lesson->feedback) {
            $lesson->feedback = $lessonmeta['feedback'] ? 1 : 0;
            $changed = true;
        }
        if (isset($lessonmeta['review']) && ($lessonmeta['review'] ? 1 : 0) !== (int) $lesson->review) {
            $lesson->review = $lessonmeta['review'] ? 1 : 0;
            $changed = true;
        }
        if (isset($lessonmeta['maxattempts']) && (int) $lessonmeta['maxattempts'] !== (int) $lesson->maxattempts) {
            $lesson->maxattempts = (int) $lessonmeta['maxattempts'];
            $changed = true;
        }
        if (isset($lessonmeta['progressbar']) && ($lessonmeta['progressbar'] ? 1 : 0) !== (int) $lesson->progressbar) {
            $lesson->progressbar = $lessonmeta['progressbar'] ? 1 : 0;
            $changed = true;
        }

        if ($changed) {
            $lesson->timemodified = time();
            $DB->update_record('lesson', $lesson);
            rebuild_course_cache($this->course->id, true);
        }
    }

    /**
     * Update an existing lesson page's content and answers.
     *
     * @param int $lessonid Lesson instance ID
     * @param int $pageid Lesson page ID
     * @param string $title Page title
     * @param string $content Page HTML content
     * @param string $pagetype Page type: 'content', 'truefalse', 'multichoice'
     * @param array $pagedata Front matter data for questions
     * @return bool True if updated
     */
    public function update_lesson_page(
        int $lessonid,
        int $pageid,
        string $title,
        string $content,
        string $pagetype,
        array $pagedata
    ): bool {
        global $DB;

        $page = $DB->get_record('lesson_pages', ['id' => $pageid, 'lessonid' => $lessonid]);
        if (!$page) {
            return false;
        }

        $page->title = $title;
        $page->contents = purify_html($content);
        $page->contentsformat = FORMAT_HTML;
        $page->timemodified = time();
        $DB->update_record('lesson_pages', $page);

        // For question pages, rebuild answers.
        if ($pagetype === 'truefalse' || $pagetype === 'multichoice') {
            // Delete existing answers.
            $DB->delete_records('lesson_answers', ['pageid' => $pageid, 'lessonid' => $lessonid]);

            // Re-insert answers.
            if ($pagetype === 'truefalse') {
                $this->insert_truefalse_answers($lessonid, $pageid, $pagedata);
            } else {
                $this->insert_multichoice_answers($lessonid, $pageid, $pagedata);
            }
        }

        return true;
    }

    /**
     * Insert true/false answers for a lesson page.
     *
     * @param int $lessonid Lesson instance ID
     * @param int $pageid Lesson page ID
     * @param array $data Front matter data
     */
    private function insert_truefalse_answers(int $lessonid, int $pageid, array $data): void {
        global $DB;

        $correctistrue = $data['correct'] ?? true;
        $now = time();

        // Answer 0: True.
        $answer = new \stdClass();
        $answer->lessonid = $lessonid;
        $answer->pageid = $pageid;
        $answer->answer = 'True';
        $answer->answerformat = FORMAT_HTML;
        $answer->response = $correctistrue ? ($data['feedback_correct'] ?? '') : ($data['feedback_incorrect'] ?? '');
        $answer->responseformat = FORMAT_HTML;
        $answer->jumpto = $correctistrue ? \LESSON_NEXTPAGE : \LESSON_THISPAGE;
        $answer->score = $correctistrue ? 1 : 0;
        $answer->flags = 0;
        $answer->timecreated = $now;
        $answer->timemodified = $now;
        $DB->insert_record('lesson_answers', $answer);

        // Answer 1: False.
        $answer2 = new \stdClass();
        $answer2->lessonid = $lessonid;
        $answer2->pageid = $pageid;
        $answer2->answer = 'False';
        $answer2->answerformat = FORMAT_HTML;
        $answer2->response = $correctistrue ? ($data['feedback_incorrect'] ?? '') : ($data['feedback_correct'] ?? '');
        $answer2->responseformat = FORMAT_HTML;
        $answer2->jumpto = $correctistrue ? \LESSON_THISPAGE : \LESSON_NEXTPAGE;
        $answer2->score = $correctistrue ? 0 : 1;
        $answer2->flags = 0;
        $answer2->timecreated = $now;
        $answer2->timemodified = $now;
        $DB->insert_record('lesson_answers', $answer2);
    }

    /**
     * Insert multichoice answers for a lesson page.
     *
     * @param int $lessonid Lesson instance ID
     * @param int $pageid Lesson page ID
     * @param array $data Front matter data with 'answers' array
     */
    private function insert_multichoice_answers(int $lessonid, int $pageid, array $data): void {
        global $DB;

        $answers = $data['answers'] ?? [];
        $now = time();

        foreach ($answers as $answerdata) {
            $iscorrect = !empty($answerdata['correct']);
            $answer = new \stdClass();
            $answer->lessonid = $lessonid;
            $answer->pageid = $pageid;
            $answer->answer = $answerdata['text'] ?? '';
            $answer->answerformat = FORMAT_HTML;
            $answer->response = $answerdata['feedback'] ?? '';
            $answer->responseformat = FORMAT_HTML;
            $answer->jumpto = $iscorrect ? \LESSON_NEXTPAGE : \LESSON_THISPAGE;
            $answer->score = $iscorrect ? 1 : 0;
            $answer->flags = 0;
            $answer->timecreated = $now;
            $answer->timemodified = $now;
            $DB->insert_record('lesson_answers', $answer);
        }
    }

    /**
     * Create a single page in an existing lesson.
     *
     * @param int $lessonid Lesson instance ID
     * @param int $cmid Course module ID
     * @param string $title Page title
     * @param string $content Page HTML content
     * @param string $pagetype Page type: 'content', 'truefalse', 'multichoice'
     * @param array $pagedata Front matter data for questions
     * @param int $afterpageid Insert after this page (0 = beginning)
     * @param bool $islast Whether this is the last page
     * @return int The new lesson_pages.id
     */
    public function create_lesson_page(
        int $lessonid,
        int $cmid,
        string $title,
        string $content,
        string $pagetype,
        array $pagedata,
        int $afterpageid = 0,
        bool $islast = false
    ): int {
        global $DB, $PAGE;

        $lesson = new \lesson($DB->get_record('lesson', ['id' => $lessonid], '*', MUST_EXIST));
        $context = \context_module::instance($cmid);
        $PAGE->set_course($this->course);

        $properties = new \stdClass();
        $properties->title = $title;
        $properties->pageid = $afterpageid;
        $properties->contents_editor = [
            'text' => purify_html($content),
            'format' => FORMAT_HTML,
        ];

        switch ($pagetype) {
            case 'truefalse':
                $properties->qtype = \LESSON_PAGE_TRUEFALSE;
                $this->setup_truefalse_answers($properties, $pagedata);
                break;

            case 'multichoice':
                $properties->qtype = \LESSON_PAGE_MULTICHOICE;
                $properties->qoption = 0;
                $this->setup_multichoice_answers($properties, $pagedata);
                break;

            case 'content':
            default:
                $properties->qtype = \LESSON_PAGE_BRANCHTABLE;
                $properties->layout = 1;
                $properties->display = 1;
                $properties->answer_editor = [];
                $properties->jumpto = [];
                $properties->answer_editor[0] = 'Continue';
                $properties->jumpto[0] = $islast ? \LESSON_EOL : \LESSON_NEXTPAGE;
                break;
        }

        $newpage = \lesson_page::create($properties, $lesson, $context, $this->course->maxbytes);
        return $newpage->id;
    }

    /**
     * Reorder lesson pages by rebuilding the doubly-linked list.
     *
     * @param int $lessonid Lesson instance ID
     * @param array $orderedpageids Ordered array of page IDs
     */
    public function reorder_lesson_pages(int $lessonid, array $orderedpageids): void {
        global $DB;

        $count = count($orderedpageids);
        for ($i = 0; $i < $count; $i++) {
            $pageid = $orderedpageids[$i];
            $previd = ($i > 0) ? $orderedpageids[$i - 1] : 0;
            $nextid = ($i < $count - 1) ? $orderedpageids[$i + 1] : 0;

            $DB->set_field('lesson_pages', 'prevpageid', $previd, ['id' => $pageid, 'lessonid' => $lessonid]);
            $DB->set_field('lesson_pages', 'nextpageid', $nextid, ['id' => $pageid, 'lessonid' => $lessonid]);
        }
    }

    /**
     * Delete a lesson page using Moodle's API.
     *
     * @param int $lessonid Lesson instance ID
     * @param int $pageid Lesson page ID
     * @param int $cmid Course module ID
     */
    public function delete_lesson_page(int $lessonid, int $pageid, int $cmid): void {
        global $DB;

        $lesson = new \lesson($DB->get_record('lesson', ['id' => $lessonid], '*', MUST_EXIST));
        $pages = $lesson->load_all_pages();

        if (isset($pages[$pageid])) {
            $pages[$pageid]->delete();
        }
    }

    /**
     * Update the "Continue" button jump on the last content page of a lesson.
     *
     * When pages are reordered, the last content page should jump to EOL.
     *
     * @param int $lessonid Lesson instance ID
     * @param array $orderedpageids Ordered page IDs
     */
    public function fix_lesson_content_jumps(int $lessonid, array $orderedpageids): void {
        global $DB;

        $count = count($orderedpageids);
        for ($i = 0; $i < $count; $i++) {
            $pageid = $orderedpageids[$i];
            $page = $DB->get_record('lesson_pages', ['id' => $pageid, 'lessonid' => $lessonid]);
            if (!$page || (int) $page->qtype !== \LESSON_PAGE_BRANCHTABLE) {
                continue;
            }

            $islast = ($i === $count - 1);
            // Get the first answer (the "Continue" button).
            $answer = $DB->get_records('lesson_answers', ['pageid' => $pageid, 'lessonid' => $lessonid], 'id ASC', '*', 0, 1);
            if (!empty($answer)) {
                $answer = reset($answer);
                $expectedjump = $islast ? \LESSON_EOL : \LESSON_NEXTPAGE;
                if ((int) $answer->jumpto !== $expectedjump) {
                    $DB->set_field('lesson_answers', 'jumpto', $expectedjump, ['id' => $answer->id]);
                }
            }
        }
    }
}
