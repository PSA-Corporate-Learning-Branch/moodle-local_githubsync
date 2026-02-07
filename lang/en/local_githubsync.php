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

$string['pluginname'] = 'GitHub Sync';
$string['githubsync:configure'] = 'Configure GitHub Sync settings';
$string['githubsync:sync'] = 'Sync course from GitHub';
$string['config'] = 'GitHub Sync Configuration';
$string['sync'] = 'Sync from GitHub';
$string['repo_url'] = 'Repository URL';
$string['repo_url_desc'] = 'Full GitHub repository URL (e.g. https://github.com/owner/repo)';
$string['repo_url_help'] = 'The full URL of the GitHub repository containing your course content. Expected format: https://github.com/owner/repo. The repository must follow the expected directory structure with a sections/ directory containing numbered section folders.';
$string['pat'] = 'Personal Access Token';
$string['pat_desc'] = 'GitHub Personal Access Token with repo scope';
$string['branch'] = 'Branch';
$string['branch_desc'] = 'Branch to sync from (default: main)';
$string['testconnection'] = 'Test Connection';
$string['connectionsuccess'] = 'Connection successful. Repository accessible.';
$string['connectionfailed'] = 'Connection failed: {$a}';
$string['savesettings'] = 'Save settings';
$string['syncsuccess'] = 'Sync completed successfully. Commit {$a->sha}: {$a->summary}';
$string['syncfailed'] = 'Sync failed: {$a}';
$string['syncuptodate'] = 'Already up to date (commit {$a}).';
$string['noconfig'] = 'GitHub Sync is not configured for this course. Please configure it first.';
$string['configsaved'] = 'GitHub Sync configuration saved.';
$string['invalidrepourl'] = 'Invalid GitHub repository URL. Expected format: https://github.com/owner/repo';
$string['syncinprogress'] = 'Syncing from GitHub...';
$string['sections_created'] = '{$a} section(s) created';
$string['sections_updated'] = '{$a} section(s) updated';
$string['activities_created'] = '{$a} activity/activities created';
$string['activities_updated'] = '{$a} activity/activities updated';
$string['activities_hidden'] = '{$a} activity/activities hidden (removed from repo)';
$string['assets_uploaded'] = '{$a} asset(s) uploaded';
$string['confirmdelete'] = 'The following activities are no longer in the repository and will be hidden: {$a}';
$string['lastsynced'] = 'Last synced';
$string['never'] = 'Never';
$string['synchistory'] = 'Sync History';
$string['task_sync_courses'] = 'GitHub Sync: Auto-sync courses';
$string['settings_general'] = 'General Settings';
$string['settings_general_desc'] = 'Configure global defaults for the GitHub Sync plugin.';
$string['settings_default_branch'] = 'Default branch';
$string['settings_default_branch_desc'] = 'Default branch name for new configurations (can be overridden per course).';
$string['settings_webhook'] = 'Webhook';
$string['settings_webhook_desc'] = 'To enable automatic sync on push, add this URL as a webhook in your GitHub repository settings:<br><code>{$a}</code><br>Set content type to <code>application/json</code> and select the <strong>push</strong> event.';
$string['settings_courses'] = 'Configured Courses';
$string['settings_courses_desc'] = 'To sync all configured courses from the command line:<br><code>php local/githubsync/cli/sync_all.php</code><br>To sync only auto-sync courses: <code>php local/githubsync/cli/sync_all.php --auto-only</code>';
$string['auto_sync'] = 'Enable auto-sync';
$string['auto_sync_desc'] = 'Automatically sync this course on a schedule (hourly).';
$string['syncfailed_generic'] = 'Sync failed. Check the sync history for details.';
$string['encryption_required'] = 'Moodle encryption keys must be configured to store PATs securely. Run php admin/cli/cfg.php to set up encryption.';
$string['settings_webhook_secret'] = 'Webhook secret';
$string['settings_webhook_secret_desc'] = 'Shared secret for GitHub webhook HMAC-SHA256 signature verification. Set this same value in your GitHub webhook configuration.';
$string['invalidpat'] = 'Invalid GitHub Personal Access Token format. Expected a token starting with ghp_ or github_pat_.';
$string['invalidbranch'] = 'Invalid branch name. Use only letters, numbers, hyphens, underscores, dots, and forward slashes.';
$string['privacy:metadata'] = 'The GitHub Sync plugin stores configuration data linking courses to GitHub repositories. It does not store personal user data beyond the user ID of who triggered a sync.';
