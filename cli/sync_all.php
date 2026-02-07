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
 * CLI script to sync all configured courses from GitHub.
 *
 * Usage:
 *   php local/githubsync/cli/sync_all.php
 *   php local/githubsync/cli/sync_all.php --courseid=9
 *   php local/githubsync/cli/sync_all.php --auto-only
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'courseid' => null,
    'auto-only' => false,
    'help' => false,
], [
    'c' => 'courseid',
    'a' => 'auto-only',
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unrecognized options:\n  {$unrecognized}\nUse --help for usage.");
}

if ($options['help']) {
    echo "Sync courses from GitHub repositories.

Usage:
  php local/githubsync/cli/sync_all.php [options]

Options:
  -c, --courseid=ID   Sync only this course ID
  -a, --auto-only     Only sync courses with auto_sync enabled
  -h, --help          Show this help

";
    exit(0);
}

// Set up admin user context.
$USER = get_admin();

// Build query conditions.
$conditions = [];
if ($options['courseid']) {
    $conditions['courseid'] = (int) $options['courseid'];
}
if ($options['auto-only']) {
    $conditions['auto_sync'] = 1;
}

$configs = $DB->get_records('local_githubsync_config', $conditions ?: null);

if (empty($configs)) {
    cli_writeln('No courses configured for GitHub Sync' .
        ($options['auto-only'] ? ' (with auto-sync enabled)' : '') . '.');
    exit(0);
}

cli_writeln('GitHub Sync: Syncing ' . count($configs) . ' course(s)...');
cli_writeln('');

$successes = 0;
$failures = 0;
$uptodate = 0;

foreach ($configs as $config) {
    try {
        $course = get_course($config->courseid);
    } catch (\Exception $e) {
        cli_writeln("Course {$config->courseid}: SKIPPED (course not found)");
        $failures++;
        continue;
    }

    cli_write("Course {$config->courseid} ({$course->shortname}): ");

    try {
        $engine = new \local_githubsync\sync\engine($course, $config);
        $result = $engine->execute();
        $engine->write_log($USER->id, $result['sha'], $result['status'], $result['summary']);

        if ($result['status'] === 'uptodate') {
            cli_writeln('up to date');
            $uptodate++;
        } else {
            cli_writeln($result['summary']);
            $successes++;
        }
    } catch (\Exception $e) {
        cli_writeln('FAILED - ' . $e->getMessage());
        $failures++;

        // Log the failure.
        $log = new \stdClass();
        $log->courseid = $config->courseid;
        $log->userid = $USER->id;
        $log->commit_sha = '';
        $log->status = 'failed';
        $log->summary = $e->getMessage();
        $log->details = json_encode(['error' => $e->getMessage()]);
        $log->timecreated = time();
        $DB->insert_record('local_githubsync_log', $log);
    }
}

cli_writeln('');
cli_writeln("Done: {$successes} synced, {$uptodate} up to date, {$failures} failed.");
