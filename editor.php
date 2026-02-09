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

/**
 * GitHub Sync file editor page.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/githubsync:configure', $context);

// Redirect to config page if no config exists.
$config = $DB->get_record('local_githubsync_config', ['courseid' => $courseid]);
if (!$config) {
    redirect(
        new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]),
        get_string('noconfig', 'local_githubsync'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$PAGE->set_url(new moodle_url('/local/githubsync/editor.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('editor_title', 'local_githubsync'));
$PAGE->set_heading($course->fullname);

// Initialise the AMD editor module.
$PAGE->requires->js_call_amd('local_githubsync/editor', 'init', [$courseid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editor_title', 'local_githubsync'));

$templatecontext = [
    'configurl' => (new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]))->out(false),
    'courseid' => $courseid,
];

echo $OUTPUT->render_from_template('local_githubsync/editor', $templatecontext);

echo $OUTPUT->footer();
