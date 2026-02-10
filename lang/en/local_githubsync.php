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
 * Language strings for local_githubsync.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['activities_created'] = '{$a} activity/activities created';
$string['activities_hidden'] = '{$a} activity/activities hidden (removed from repo)';
$string['activities_updated'] = '{$a} activity/activities updated';
$string['assets_uploaded'] = '{$a} asset(s) uploaded';
$string['chapters_created'] = '{$a} chapter(s) created';
$string['chapters_hidden'] = '{$a} chapter(s) hidden (removed from repo)';
$string['chapters_updated'] = '{$a} chapter(s) updated';
$string['lessonpages_created'] = '{$a} lesson page(s) created';
$string['lessonpages_hidden'] = '{$a} lesson page(s) removed';
$string['lessonpages_updated'] = '{$a} lesson page(s) updated';
$string['auto_sync'] = 'Enable auto-sync';
$string['auto_sync_desc'] = 'Automatically sync this course on a schedule (hourly).';
$string['branch'] = 'Branch';
$string['branch_desc'] = 'Branch to sync from (default: main)';
$string['config'] = 'GitHub Sync Configuration';
$string['configsaved'] = 'GitHub Sync configuration saved.';
$string['confirmdelete'] = 'The following activities are no longer in the repository and will be hidden: {$a}';
$string['connectionfailed'] = 'Connection failed: {$a}';
$string['connectionsuccess'] = 'Connection successful. Repository accessible.';
$string['encryption_required'] = 'Moodle encryption keys must be configured to store PATs securely. Run php admin/cli/cfg.php to set up encryption.';
$string['githubsync:configure'] = 'Configure GitHub Sync settings';
$string['githubsync:sync'] = 'Sync course from GitHub';
$string['invalidbranch'] = 'Invalid branch name. Use only letters, numbers, hyphens, underscores, dots, and forward slashes.';
$string['invalidpat'] = 'Invalid GitHub Personal Access Token format. Expected a token starting with ghp_ or github_pat_.';
$string['invalidrepourl'] = 'Invalid GitHub repository URL. Expected format: https://github.com/owner/repo';
$string['lastsynced'] = 'Last synced';
$string['never'] = 'Never';
$string['noconfig'] = 'GitHub Sync is not configured for this course. Please configure it first.';
$string['pat'] = 'Personal Access Token';
$string['pat_desc'] = 'GitHub Personal Access Token with repo scope';
$string['pluginname'] = 'GitHub Sync';
$string['privacy:metadata'] = 'The GitHub Sync plugin stores configuration data linking courses to GitHub repositories. It does not store personal user data beyond the user ID of who triggered a sync.';
$string['repo_url'] = 'Repository URL';
$string['repo_url_desc'] = 'Full GitHub repository URL (e.g. https://github.com/owner/repo)';
$string['repo_url_help'] = 'The full URL of the GitHub repository containing your course content. Expected format: https://github.com/owner/repo. The repository must follow the expected directory structure with a sections/ directory containing numbered section folders.';
$string['savesettings'] = 'Save settings';
$string['sections_created'] = '{$a} section(s) created';
$string['sections_updated'] = '{$a} section(s) updated';
$string['settings_courses'] = 'Configured Courses';
$string['settings_courses_desc'] = 'To sync all configured courses from the command line:<br><code>php local/githubsync/cli/sync_all.php</code><br>To sync only auto-sync courses: <code>php local/githubsync/cli/sync_all.php --auto-only</code>';
$string['settings_default_branch'] = 'Default branch';
$string['settings_default_branch_desc'] = 'Default branch name for new configurations (can be overridden per course).';
$string['settings_general'] = 'General Settings';
$string['settings_general_desc'] = 'Configure global defaults for the GitHub Sync plugin.';
$string['settings_webhook'] = 'Webhook';
$string['settings_webhook_desc'] = 'To enable automatic sync on push, add this URL as a webhook in your GitHub repository settings:<br><code>{$a}</code><br>Set content type to <code>application/json</code> and select the <strong>push</strong> event.';
$string['settings_webhook_secret'] = 'Webhook secret';
$string['settings_webhook_secret_desc'] = 'Shared secret for GitHub webhook HMAC-SHA256 signature verification. Set this same value in your GitHub webhook configuration.';
$string['sync'] = 'Sync from GitHub';
$string['syncfailed'] = 'Sync failed: {$a}';
$string['syncfailed_generic'] = 'Sync failed. Check the sync history for details.';
$string['synchistory'] = 'Sync History';
$string['syncinprogress'] = 'Syncing from GitHub...';
$string['syncsuccess'] = 'Sync completed successfully. Commit {$a->sha}: {$a->summary}';
$string['syncuptodate'] = 'Already up to date (commit {$a}).';
$string['task_sync_courses'] = 'GitHub Sync: Auto-sync courses';
$string['testconnection'] = 'Test Connection';

// Editor strings.
$string['editor_title'] = 'File Editor';
$string['editor_back'] = 'Back to settings';
$string['editor_refresh'] = 'Refresh';
$string['editor_files'] = 'Files';
$string['editor_loading'] = 'Loading...';
$string['editor_nofile'] = 'No file selected';
$string['editor_selectfile'] = 'Select a file from the tree to view and edit its contents.';
$string['editor_unsaved'] = 'Unsaved changes';
$string['editor_commitmessage'] = 'Commit message';
$string['editor_commitmessage_placeholder'] = 'Describe your changes...';
$string['editor_save'] = 'Save &amp; commit';
$string['editor_saving'] = 'Saving...';
$string['editor_saved'] = 'Saved successfully (commit {$a}).';
$string['editor_conflict'] = 'This file has been modified by someone else. Please reload the file and try again.';
$string['editor_unsaved_confirm'] = 'You have unsaved changes. Are you sure you want to leave?';
$string['editor_binary'] = 'Binary file (not editable)';
$string['editor_empty_message'] = 'Please enter a commit message.';
$string['editor_loadfailed'] = 'Failed to load file content.';
$string['editor_treefailed'] = 'Failed to load file tree.';
$string['editor_reload'] = 'Reload file';
$string['editor_pat_noaccess'] = 'Your GitHub Personal Access Token does not have write permission. For fine-grained tokens, set Contents permission to "Read and write". For classic tokens, ensure the "repo" scope is enabled. GitHub said: {$a}';
