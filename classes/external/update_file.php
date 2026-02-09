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

namespace local_githubsync\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_githubsync\github\client;
use local_githubsync\sync\engine;

/**
 * Update a file in a GitHub-synced course repository.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_file extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'filepath' => new external_value(PARAM_PATH, 'File path within the repository'),
            'content' => new external_value(PARAM_RAW, 'New file content'),
            'sha' => new external_value(PARAM_RAW, 'Current blob SHA for conflict detection'),
            'message' => new external_value(PARAM_RAW, 'Commit message'),
        ]);
    }

    /**
     * Update a file.
     *
     * @param int $courseid
     * @param string $filepath
     * @param string $content
     * @param string $sha
     * @param string $message
     * @return array
     */
    public static function execute(int $courseid, string $filepath, string $content, string $sha, string $message): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'filepath' => $filepath,
            'content' => $content,
            'sha' => $sha,
            'message' => $message,
        ]);
        $courseid = $params['courseid'];
        $filepath = $params['filepath'];
        $content = $params['content'];
        $sha = $params['sha'];
        $message = $params['message'];

        // Path traversal protection.
        if (strpos($filepath, '..') !== false) {
            throw new \invalid_parameter_exception('Invalid file path');
        }

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/githubsync:configure', $context);

        $config = $DB->get_record('local_githubsync_config', ['courseid' => $courseid]);
        if (!$config) {
            throw new \moodle_exception('noconfig', 'local_githubsync');
        }

        $pat = engine::decrypt_pat($config->pat_encrypted);
        $ghclient = new client($config->repo_url, $pat, $config->branch);

        try {
            $result = $ghclient->update_file($filepath, $content, $sha, $message);
            return [
                'success' => true,
                'newsha' => $result['sha'],
                'commitsha' => $result['commit_sha'],
                'conflict' => false,
            ];
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'editor_conflict') {
                return [
                    'success' => false,
                    'newsha' => '',
                    'commitsha' => '',
                    'conflict' => true,
                ];
            }
            throw $e;
        }
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the update succeeded'),
            'newsha' => new external_value(PARAM_RAW, 'New blob SHA after update'),
            'commitsha' => new external_value(PARAM_RAW, 'Commit SHA'),
            'conflict' => new external_value(PARAM_BOOL, 'Whether a SHA conflict occurred'),
        ]);
    }
}
