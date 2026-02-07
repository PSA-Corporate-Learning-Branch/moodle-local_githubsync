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

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/githubsync:sync', $context);
require_sesskey();

$PAGE->set_url(new moodle_url('/local/githubsync/sync.php', ['courseid' => $courseid]));
$PAGE->set_context($context);

// Load config.
$config = $DB->get_record('local_githubsync_config', ['courseid' => $courseid]);
if (!$config) {
    redirect(
        new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]),
        get_string('noconfig', 'local_githubsync'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Run the sync.
$engine = new \local_githubsync\sync\engine($course, $config);

try {
    $result = $engine->execute();

    // Write to log.
    $engine->write_log($USER->id, $result['sha'], $result['status'], $result['summary']);

    if ($result['status'] === 'uptodate') {
        redirect(
            new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]),
            $result['summary'],
            null,
            \core\output\notification::NOTIFY_INFO
        );
    } else {
        $a = new stdClass();
        $a->sha = substr($result['sha'], 0, 7);
        $a->summary = $result['summary'];
        redirect(
            new moodle_url('/course/view.php', ['id' => $courseid]),
            get_string('syncsuccess', 'local_githubsync', $a),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
} catch (\Exception $e) {
    // Log the failure.
    $engine->write_log($USER->id, '', 'failed', $e->getMessage());

    redirect(
        new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]),
        get_string('syncfailed', 'local_githubsync', $e->getMessage()),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
