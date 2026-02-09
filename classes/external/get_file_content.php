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
 * Get file content and SHA from a GitHub-synced course repository.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_file_content extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'filepath' => new external_value(PARAM_PATH, 'File path within the repository'),
        ]);
    }

    /**
     * Get file content.
     *
     * @param int $courseid
     * @param string $filepath
     * @return array
     */
    public static function execute(int $courseid, string $filepath): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'filepath' => $filepath,
        ]);
        $courseid = $params['courseid'];
        $filepath = $params['filepath'];

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
        $result = $ghclient->get_file_content_with_sha($filepath);

        return [
            'content' => $result['content'],
            'sha' => $result['sha'],
            'path' => $result['path'],
            'name' => $result['name'],
            'size' => $result['size'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'content' => new external_value(PARAM_RAW, 'File content'),
            'sha' => new external_value(PARAM_RAW, 'Blob SHA'),
            'path' => new external_value(PARAM_RAW, 'File path'),
            'name' => new external_value(PARAM_RAW, 'File name'),
            'size' => new external_value(PARAM_INT, 'File size in bytes'),
        ]);
    }
}
