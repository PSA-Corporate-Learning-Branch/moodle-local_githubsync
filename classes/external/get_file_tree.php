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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_githubsync\github\client;
use local_githubsync\sync\engine;

/**
 * Get the file tree for a GitHub-synced course repository.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_file_tree extends external_api {

    /** @var array File extensions considered binary */
    private const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'bmp', 'ico', 'svg', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'tar', 'gz', 'rar', '7z',
        'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'ogg',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'exe', 'dll', 'so', 'dylib',
    ];

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get the file tree.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/githubsync:configure', $context);

        $config = $DB->get_record('local_githubsync_config', ['courseid' => $courseid]);
        if (!$config) {
            throw new \moodle_exception('noconfig', 'local_githubsync');
        }

        $pat = engine::decrypt_pat($config->pat_encrypted);
        $ghclient = new client($config->repo_url, $pat, $config->branch);
        $tree = $ghclient->get_tree();

        $files = [];
        foreach ($tree as $item) {
            $path = $item['path'];
            $type = $item['type'] === 'tree' ? 'dir' : 'file';
            $name = basename($path);
            $size = $item['size'] ?? 0;

            $isbinary = false;
            if ($type === 'file') {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $isbinary = in_array($ext, self::BINARY_EXTENSIONS);
            }

            $files[] = [
                'path' => $path,
                'type' => $type,
                'name' => $name,
                'size' => $size,
                'isbinary' => $isbinary,
            ];
        }

        return ['files' => $files];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'path' => new external_value(PARAM_RAW, 'File path'),
                    'type' => new external_value(PARAM_ALPHA, 'Type: file or dir'),
                    'name' => new external_value(PARAM_RAW, 'File name'),
                    'size' => new external_value(PARAM_INT, 'File size in bytes'),
                    'isbinary' => new external_value(PARAM_BOOL, 'Whether the file is binary'),
                ])
            ),
        ]);
    }
}
