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

/**
 * Handles creating and updating Moodle course structure from repo data.
 */
class course_builder {

    /** @var \stdClass The course object */
    private \stdClass $course;

    /** @var array Module IDs from the modules table, keyed by module name */
    private array $moduleids = [];

    public function __construct(\stdClass $course) {
        global $DB;
        $this->course = $course;

        // Cache module IDs for the types we support.
        foreach (['page', 'label', 'url'] as $modname) {
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
            $update->shortname = $coursedata['shortname'];
            $changed = true;
        }
        if (isset($coursedata['summary']) && $coursedata['summary'] !== $this->course->summary) {
            $update->summary = $coursedata['summary'];
            $update->summaryformat = FORMAT_HTML;
            $changed = true;
        }
        if (!empty($coursedata['format']) && $coursedata['format'] !== $this->course->format) {
            $update->format = $coursedata['format'];
            $changed = true;
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
            $updatedata['summary'] = $sectiondata['summary'];
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

        // Page-specific fields.
        $moduleinfo->content = $htmlcontent;
        $moduleinfo->contentformat = FORMAT_HTML;
        $moduleinfo->display = RESOURCELIB_DISPLAY_OPEN;
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

        // Update the page record directly for content changes.
        $page->name = $name;
        $page->content = $htmlcontent;
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

        // Label uses intro as its content.
        $moduleinfo->intro = $htmlcontent;
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
        $moduleinfo->display = RESOURCELIB_DISPLAY_AUTO;

        $moduleinfo->intro = $intro;
        $moduleinfo->introformat = FORMAT_HTML;

        $result = add_moduleinfo($moduleinfo, $this->course);

        return $result->coursemodule;
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
                    throw new \moodle_exception('syncfailed', 'local_githubsync', '',
                        "URL activity requires 'url' in front matter");
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
}
