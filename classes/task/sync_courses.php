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

namespace local_githubsync\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task that syncs all courses with auto_sync enabled.
 */
class sync_courses extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_sync_courses', 'local_githubsync');
    }

    public function execute(): void {
        global $DB;

        $configs = $DB->get_records('local_githubsync_config', ['auto_sync' => 1]);

        if (empty($configs)) {
            mtrace('GitHub Sync: No courses configured for auto-sync.');
            return;
        }

        mtrace('GitHub Sync: Found ' . count($configs) . ' course(s) to sync.');

        foreach ($configs as $config) {
            $this->sync_course($config);
        }
    }

    /**
     * Sync a single course.
     *
     * @param \stdClass $config The config record
     */
    private function sync_course(\stdClass $config): void {
        global $DB, $USER;

        try {
            $course = get_course($config->courseid);
        } catch (\Exception $e) {
            mtrace("  Course {$config->courseid}: SKIPPED (course not found)");
            return;
        }

        mtrace("  Course {$config->courseid} ({$course->shortname}): syncing...");

        try {
            $engine = new \local_githubsync\sync\engine($course, $config);
            $result = $engine->execute();

            $userid = $config->created_by ?: ($USER->id ?? 0);
            $engine->write_log($userid, $result['sha'], $result['status'], $result['summary']);

            mtrace("    {$result['status']}: {$result['summary']}");
        } catch (\Exception $e) {
            mtrace("    FAILED: {$e->getMessage()}");

            // Log the failure.
            $log = new \stdClass();
            $log->courseid = $config->courseid;
            $log->userid = $config->created_by ?: 0;
            $log->commit_sha = '';
            $log->status = 'failed';
            $log->summary = $e->getMessage();
            $log->details = json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $log->timecreated = time();
            $DB->insert_record('local_githubsync_log', $log);
        }
    }
}
