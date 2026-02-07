# GitHub Sync for Moodle

A Moodle local plugin that syncs course content from a GitHub repository. Content creators author HTML files in a structured repo, and Moodle pulls it in to build and update the course automatically.

## Features

- **One-click sync** from a GitHub repository into a Moodle course
- **Automatic section creation** based on directory structure
- **Page, Label, and URL activities** created from HTML files
- **YAML front matter** support for controlling activity types
- **Asset management** — CSS, images, and JS uploaded to Moodle file storage with automatic URL rewriting
- **Incremental sync** — only changed files are updated (content hash tracking)
- **Delete detection** — removed files are automatically hidden in the course
- **Scheduled auto-sync** — hourly cron task for configured courses
- **GitHub webhook** endpoint for instant sync on push
- **CLI tool** for bulk syncing all configured courses
- **PAT encryption** at rest using Moodle's Sodium-based encryption
- **Per-course configuration** with capability-based access control
- **Sync history** with commit SHA tracking and detailed operation logs

## Requirements

- Moodle 4.1 or later
- PHP 8.0 or later
- A GitHub repository with the expected directory structure
- A GitHub Personal Access Token (PAT) with `repo` scope (or `public_repo` for public repos)

## Installation

1. Copy the `githubsync` directory to `/local/githubsync` in your Moodle installation
2. Visit **Site Administration > Notifications** to trigger the database install
3. Or run from the command line:
   ```bash
   php admin/cli/upgrade.php
   ```

## Repository Structure

Your GitHub repository should follow this structure:

```
course.yaml                        # Course metadata (optional)
sections/
  01-introduction/
    section.yaml                   # Section title and summary (optional)
    01-welcome.html                # -> Page activity "Welcome"
    02-overview.html               # -> Page activity "Overview"
    03-notice.html                 # -> Label activity (with front matter)
  02-module-one/
    section.yaml
    01-lesson.html
    02-external-link.html          # -> URL activity (with front matter)
assets/
  css/
    custom.css                     # Shared stylesheets
  images/
    diagram.png                    # Shared images
  js/
    interactions.js                # Shared scripts
```

### course.yaml

Optional. Updates the Moodle course metadata on sync.

```yaml
fullname: "Introduction to Digital Media"
shortname: "IDM101"
summary: "An introductory course covering..."
format: topics
visible: true
```

### section.yaml

Optional per section. Sets the section title and summary.

```yaml
title: "Module 1: Getting Started"
summary: "<p>In this module you will learn the fundamentals.</p>"
visible: true
```

### HTML Files

Plain HTML fragments (no `<html>` or `<body>` tags). Each file becomes a Moodle activity. The filename determines the activity name:

- `01-welcome.html` → "Welcome"
- `02-interactive-lesson.html` → "Interactive Lesson"

The numeric prefix controls ordering and is stripped from the name.

### YAML Front Matter

Add a YAML block at the top of an HTML file to control the activity type:

**Label activity:**
```html
---
type: label
---
<div class="alert alert-info">
  <strong>Note:</strong> This becomes a Label on the course page.
</div>
```

**URL activity:**
```html
---
type: url
name: "Moodle Documentation"
url: "https://docs.moodle.org"
---
<p>Optional description text.</p>
```

**Supported front matter fields:**
| Field | Description |
|-------|-------------|
| `type` | Activity type: `page` (default), `label`, `url` |
| `name` | Override the activity name (otherwise derived from filename) |
| `url` | External URL (required for `type: url`) |
| `visible` | `true` or `false` |

### Assets

Files in the `assets/` directory are uploaded to Moodle's file storage. References in HTML are automatically rewritten:

```html
<!-- You write: -->
<link rel="stylesheet" href="assets/css/custom.css">
<img src="assets/images/diagram.png" alt="Diagram">

<!-- Moodle sees: -->
<link rel="stylesheet" href="/pluginfile.php/.../local_githubsync/assets/.../css/custom.css">
<img src="/pluginfile.php/.../local_githubsync/assets/.../images/diagram.png" alt="Diagram">
```

## Usage

### Per-Course Configuration

1. Navigate to your course
2. Go to **Course Settings > GitHub Sync** (in the settings navigation)
3. Enter:
   - **Repository URL**: e.g. `https://github.com/yourorg/course-content`
   - **Personal Access Token**: a GitHub PAT with `repo` scope
   - **Branch**: the branch to sync from (default: `main`)
   - **Enable auto-sync**: check to include in hourly scheduled sync
4. Click **Save settings**
5. Click **Sync from GitHub** to run the first sync

### Sync Behavior

**First sync:**
- Creates Moodle sections matching the `sections/` directories
- Creates Page/Label/URL activities from HTML files
- Uploads assets to Moodle file storage
- Records the commit SHA

**Subsequent syncs:**
- Compares current commit SHA to stored SHA
- Skips if already up to date
- Only updates activities whose content hash has changed
- Creates new activities for new files
- Hides activities for removed files (does not delete)
- Updates assets if changed

**The repository is the single source of truth.** If someone edits a Page directly in Moodle and then a sync runs, the repo version overwrites the Moodle edit.

### Admin Settings

Visit **Site Administration > Plugins > Local plugins > GitHub Sync** to configure:
- Default branch name for new configurations
- View webhook URL for GitHub integration
- CLI sync commands

### GitHub Webhook (Instant Sync)

For immediate sync when content is pushed:

1. In your GitHub repository, go to **Settings > Webhooks > Add webhook**
2. Set **Payload URL** to: `https://yourmoodle.com/local/githubsync/webhook.php`
3. Set **Content type** to: `application/json`
4. Select **Just the push event**
5. Click **Add webhook**

The webhook matches the repository URL and branch to find configured courses and syncs them automatically.

### Scheduled Auto-Sync

Enable **auto-sync** in the per-course configuration. The scheduled task runs hourly and syncs all courses with auto-sync enabled.

To manually trigger the scheduled task:
```bash
php admin/cli/scheduled_task.php --execute='\local_githubsync\task\sync_courses'
```

### CLI Bulk Sync

Sync all configured courses:
```bash
php local/githubsync/cli/sync_all.php
```

Sync a specific course:
```bash
php local/githubsync/cli/sync_all.php --courseid=9
```

Sync only auto-sync courses:
```bash
php local/githubsync/cli/sync_all.php --auto-only
```

## Capabilities

| Capability | Description | Default roles |
|------------|-------------|---------------|
| `local/githubsync:configure` | Configure GitHub Sync settings for a course | Manager, Editing Teacher |
| `local/githubsync:sync` | Trigger a sync from GitHub | Manager, Editing Teacher |

## Database Tables

| Table | Purpose |
|-------|---------|
| `local_githubsync_config` | Per-course configuration (repo URL, encrypted PAT, branch, last sync SHA) |
| `local_githubsync_mapping` | Maps repo file paths to Moodle course module IDs and content hashes |
| `local_githubsync_log` | Sync operation history with status, commit SHA, and detailed JSON logs |

## Security

- **PAT encryption**: Personal Access Tokens are encrypted at rest using Moodle's Sodium-based encryption API (`\core\encryption`). A base64 fallback is used if the encryption key hasn't been set up.
- **Capability checks**: All actions require appropriate capabilities in the course context.
- **Session key validation**: The sync trigger page requires a valid sesskey.
- **No git required**: Uses the GitHub REST API only — no git binary needed on the server.

## API Rate Limits

GitHub allows 5,000 API requests per hour with a PAT. A typical sync uses:
- 1 request for the commit SHA
- 1 request for the tree
- 1 request per file fetched

A course with 50 HTML files and 10 assets would use ~62 requests per full sync. Incremental syncs use fewer requests since unchanged files are skipped via content hashing.

The plugin tracks rate limit headers and provides a clear error message if the limit is exceeded.

## File Structure

```
local/githubsync/
  version.php                          # Plugin version and requirements
  lib.php                              # Navigation hooks and pluginfile handler
  config.php                           # Per-course configuration page
  sync.php                             # Sync trigger page
  webhook.php                          # GitHub webhook endpoint
  settings.php                         # Global admin settings
  db/
    install.xml                        # Database schema
    access.php                         # Capability definitions
    tasks.php                          # Scheduled task registration
  lang/en/
    local_githubsync.php               # Language strings
  classes/
    form/
      config_form.php                  # Per-course config form (moodleform)
    github/
      client.php                       # GitHub REST API client
    sync/
      engine.php                       # Core sync orchestrator
      course_builder.php               # Creates/updates Moodle course structure
      asset_handler.php                # Asset upload and URL rewriting
    task/
      sync_courses.php                 # Scheduled task for auto-sync
  cli/
    sync_all.php                       # CLI script for bulk sync
```

## License

This plugin is licensed under the [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.html), consistent with Moodle's license.
