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

defined('MOODLE_INTERNAL') || die();

/**
 * Add GitHub Sync node to course settings navigation.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
function local_githubsync_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    if ($context->contextlevel !== CONTEXT_COURSE) {
        return;
    }

    $courseid = $context->instanceid;
    if ($courseid == SITEID) {
        return;
    }

    // Check if user has either configure or sync capability.
    if (!has_capability('local/githubsync:configure', $context) &&
        !has_capability('local/githubsync:sync', $context)) {
        return;
    }

    $coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$coursenode) {
        return;
    }

    $node = $coursenode->add(
        get_string('pluginname', 'local_githubsync'),
        new moodle_url('/local/githubsync/config.php', ['courseid' => $courseid]),
        navigation_node::TYPE_SETTING,
        null,
        'local_githubsync',
        new pix_icon('i/repository', '')
    );
}

/**
 * Serve files from the local_githubsync file areas.
 *
 * @param stdClass $course The course object
 * @param stdClass $cm Course module (not used)
 * @param context $context The context
 * @param string $filearea The file area
 * @param array $args Extra arguments
 * @param bool $forcedownload Whether to force download
 * @param array $options Additional options
 * @return bool False if file not found
 */
function local_githubsync_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_COURSE) {
        return false;
    }

    if ($filearea !== 'assets') {
        return false;
    }

    require_course_login($course, true);

    $itemid = array_shift($args);
    $relativepath = implode('/', $args);
    $filename = basename($relativepath);
    $filepath = '/' . (dirname($relativepath) === '.' ? '' : dirname($relativepath) . '/');

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_githubsync', $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
