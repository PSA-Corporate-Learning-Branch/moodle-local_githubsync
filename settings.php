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

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_githubsync', get_string('pluginname', 'local_githubsync'));

    // General settings header.
    $settings->add(new admin_setting_heading(
        'local_githubsync/general',
        get_string('settings_general', 'local_githubsync'),
        get_string('settings_general_desc', 'local_githubsync')
    ));

    // Default branch.
    $settings->add(new admin_setting_configtext(
        'local_githubsync/default_branch',
        get_string('settings_default_branch', 'local_githubsync'),
        get_string('settings_default_branch_desc', 'local_githubsync'),
        'main',
        PARAM_TEXT
    ));

    // Webhook URL display (read-only info).
    $webhookurl = new moodle_url('/local/githubsync/webhook.php');
    $settings->add(new admin_setting_heading(
        'local_githubsync/webhook_info',
        get_string('settings_webhook', 'local_githubsync'),
        get_string('settings_webhook_desc', 'local_githubsync', $webhookurl->out())
    ));

    // Configured courses info.
    $syncallurl = new moodle_url('/local/githubsync/cli/sync_all.php');
    $settings->add(new admin_setting_heading(
        'local_githubsync/courses_info',
        get_string('settings_courses', 'local_githubsync'),
        get_string('settings_courses_desc', 'local_githubsync')
    ));

    $ADMIN->add('localplugins', $settings);
}
