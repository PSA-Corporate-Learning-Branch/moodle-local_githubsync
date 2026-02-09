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
 * External function definitions for local_githubsync.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_githubsync_get_file_tree' => [
        'classname' => 'local_githubsync\external\get_file_tree',
        'description' => 'Get the file tree for a GitHub-synced course repository',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/githubsync:configure',
    ],
    'local_githubsync_get_file_content' => [
        'classname' => 'local_githubsync\external\get_file_content',
        'description' => 'Get the content and SHA of a file from a GitHub-synced course repository',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/githubsync:configure',
    ],
    'local_githubsync_update_file' => [
        'classname' => 'local_githubsync\external\update_file',
        'description' => 'Update a file in a GitHub-synced course repository',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/githubsync:configure',
    ],
];
