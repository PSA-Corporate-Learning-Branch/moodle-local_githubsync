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
require_capability('local/githubsync:configure', $context);

$PAGE->set_url(new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('config', 'local_githubsync'));
$PAGE->set_heading($course->fullname);

// Load existing config if any.
$config = $DB->get_record('local_githubsync_config', ['courseid' => $courseid]);

$customdata = [];
if ($config) {
    $customdata['last_sync_time'] = $config->last_sync_time;
    $customdata['last_sync_sha'] = $config->last_sync_sha;
}

$form = new \local_githubsync\form\config_form(null, $customdata);

// Set form defaults from existing config.
if ($config) {
    $formdata = new stdClass();
    $formdata->courseid = $courseid;
    $formdata->repo_url = $config->repo_url;
    $formdata->branch = $config->branch;
    $formdata->auto_sync = $config->auto_sync;
    // PAT is not pre-filled for security — blank means "keep existing".
    $form->set_data($formdata);
} else {
    $form->set_data(['courseid' => $courseid]);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

if ($data = $form->get_data()) {
    $now = time();

    if ($config) {
        // Update existing.
        $config->repo_url = $data->repo_url;
        $config->branch = $data->branch;
        $config->auto_sync = $data->auto_sync ?? 0;
        $config->timemodified = $now;

        // Only update PAT if a new one was provided.
        if (!empty($data->pat)) {
            $config->pat_encrypted = \local_githubsync\sync\engine::encrypt_pat($data->pat);
        }

        $DB->update_record('local_githubsync_config', $config);
    } else {
        // Insert new.
        $config = new stdClass();
        $config->courseid = $courseid;
        $config->repo_url = $data->repo_url;
        $config->pat_encrypted = !empty($data->pat) ? \local_githubsync\sync\engine::encrypt_pat($data->pat) : '';
        $config->branch = $data->branch;
        $config->auto_sync = 0;
        $config->created_by = $USER->id;
        $config->timecreated = $now;
        $config->timemodified = $now;

        $DB->insert_record('local_githubsync_config', $config);
    }

    redirect(
        new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]),
        get_string('configsaved', 'local_githubsync'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('config', 'local_githubsync'));

$form->display();

// Show sync button if config exists and user has sync capability.
$config = $DB->get_record('local_githubsync_config', ['courseid' => $courseid]);
if ($config && has_capability('local/githubsync:sync', $context)) {
    $syncurl = new moodle_url('/local/githubsync/sync.php', ['courseid' => $courseid, 'sesskey' => sesskey()]);
    echo html_writer::div(
        html_writer::link($syncurl, get_string('sync', 'local_githubsync'), [
            'class' => 'btn btn-primary mt-3',
        ]),
        'mt-3'
    );
}

// Show recent sync history.
$logs = $DB->get_records('local_githubsync_log', ['courseid' => $courseid], 'timecreated DESC', '*', 0, 10);
if ($logs) {
    echo $OUTPUT->heading(get_string('lastsynced', 'local_githubsync'), 3, 'mt-4');
    $table = new html_table();
    $table->head = ['Time', 'Status', 'Commit', 'Summary'];
    $table->attributes['class'] = 'generaltable';
    foreach ($logs as $log) {
        $table->data[] = [
            userdate($log->timecreated),
            s($log->status),
            s(substr($log->commit_sha ?? '', 0, 7)),
            format_text($log->summary, FORMAT_PLAIN),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
