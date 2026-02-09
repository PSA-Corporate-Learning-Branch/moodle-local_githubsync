# GitHub Sync for Moodle

A Moodle local plugin that syncs course content from a GitHub repository. Content creators author HTML files in a structured repo, and Moodle pulls it in to build and update the course automatically.

## Features

- **One-click sync** from a GitHub repository into a Moodle course
- **Automatic section creation** based on directory structure
- **Page, Label, URL, and Book activities** created from HTML files and directories
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
    02-course-handbook/            # -> Book activity "Course Handbook"
      book.yaml                    # Optional: title, numbering, intro
      01-getting-started.html      # -> Chapter 1
      02-assessment-guide.html     # -> Chapter 2
      03-resources.html            # -> Chapter 3
    03-overview.html               # -> Page activity "Overview"
    04-notice.html                 # -> Label activity (with front matter)
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
| `type` | Activity type: `page` (default), `label`, or `url`. Books are created from directories, not front matter. Other Moodle activity types (quiz, forum, assign, etc.) are not yet implemented. Unrecognized types are treated as `page`. |
| `name` | Override the activity name (otherwise derived from filename) |
| `url` | External URL (required for `type: url`) |
| `visible` | `true` or `false` |

### Book Activities

A subdirectory inside a section directory becomes a **Book** activity. Each `.html` file in the subdirectory becomes a chapter, ordered by numeric prefix. This maps one directory to one Moodle book module.

```
sections/
  01-introduction/
    02-course-handbook/              # -> Book "Course Handbook"
      book.yaml                      # Optional metadata
      01-getting-started.html        # -> Chapter 1 "Getting Started"
      02-assessment-guide.html       # -> Chapter 2 "Assessment Guide"
      03-resources.html              # -> Chapter 3 "Resources"
```

#### book.yaml

Optional. Sets book-level metadata:

```yaml
title: "Course Handbook"
numbering: bullets
intro: "<p>Reference handbook for this course.</p>"
```

| Field | Description |
|-------|-------------|
| `title` | Override the book name (otherwise derived from directory name) |
| `numbering` | Chapter numbering style: `none`, `numbers` (default), `bullets`, or `indented` |
| `intro` | HTML description shown on the book's intro page |

#### Chapter Front Matter

Chapter HTML files support YAML front matter for per-chapter settings:

```html
---
title: "Getting Started Guide"
subchapter: true
---
<h2>Welcome</h2>
<p>This chapter covers...</p>
```

| Field | Description |
|-------|-------------|
| `title` | Override the chapter title (otherwise derived from filename) |
| `subchapter` | `true` to make this a sub-chapter (indented under the previous chapter). The first chapter in a book cannot be a subchapter. |

#### Sync Behavior for Books

- **New book directory**: Creates the book activity and all chapters in one operation
- **Modified chapter**: Only the changed chapter is updated (content hash tracking)
- **New chapter file**: Added at the correct position based on filename ordering
- **Removed chapter file**: The chapter is hidden (not deleted), matching the behavior for removed pages
- **Modified book.yaml**: Updates book name, numbering, and intro without touching chapters
- **Reordered chapters** (renamed with different numeric prefixes): Page numbers are updated even if content is unchanged

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
- **Webhook secret** for HMAC-SHA256 signature verification (required for webhooks)
- Default branch name for new configurations
- View webhook URL for GitHub integration
- CLI sync commands

### GitHub Webhook (Instant Sync)

For immediate sync when content is pushed:

1. In Moodle, go to **Site Administration > Plugins > Local plugins > GitHub Sync**
2. Set a **Webhook secret** (any random string) and save
3. In your GitHub repository, go to **Settings > Webhooks > Add webhook**
4. Set **Payload URL** to: `https://yourmoodle.com/local/githubsync/webhook.php`
5. Set **Content type** to: `application/json`
6. Set **Secret** to the same value you entered in Moodle
7. Select **Just the push event**
8. Click **Add webhook**

The webhook verifies the HMAC-SHA256 signature, matches the repository URL and branch to find configured courses, and syncs them automatically.

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

- **Webhook authentication**: The webhook endpoint requires HMAC-SHA256 signature verification. Configure a shared secret in both Moodle admin settings and your GitHub webhook. Requests without a valid `X-Hub-Signature-256` header are rejected.
- **PAT encryption**: Personal Access Tokens are encrypted at rest using Moodle's Sodium-based encryption API (`\core\encryption`). Encryption keys must be configured — there is no insecure fallback. Legacy base64-encoded tokens from older versions are automatically migrated to Sodium on first access.
- **HTML sanitization**: All HTML content from GitHub repositories is passed through `purify_html()` (HTMLPurifier) before storage, stripping `<script>` tags, event handlers, and other XSS vectors.
- **Asset type allowlist**: Only safe file types (CSS, JS, images, fonts, etc.) are synced from the `assets/` directory. SVG, PHP, and other potentially dangerous file types are blocked.
- **Input validation**: Repository URLs are validated against a strict anchored regex, branch names are restricted to safe characters, and PATs are validated against GitHub's known token formats.
- **Capability checks**: All actions require appropriate capabilities in the course context.
- **Session key validation**: The sync trigger page requires a valid sesskey (CSRF protection).
- **No information leakage**: Error messages shown to users are generic; detailed error information is stored in internal logs only. The webhook endpoint returns generic responses regardless of outcome.
- **No guest access**: Asset files served via `pluginfile.php` require authenticated course enrollment — no guest auto-login.
- **No git required**: Uses the GitHub REST API only — no git binary needed on the server.

For full security documentation, see [SECURITY.md](SECURITY.md).

## API Rate Limits

GitHub allows 5,000 API requests per hour with a PAT. A typical sync uses:
- 1 request for the commit SHA
- 1 request for the tree
- 1 request per file fetched

A course with 50 HTML files and 10 assets would use ~62 requests per full sync. Incremental syncs use fewer requests since unchanged files are skipped via content hashing.

The plugin tracks rate limit headers and provides a clear error message if the limit is exceeded.

## CI/CD

Every push and pull request to `main` runs two automated checks:

### Semgrep OWASP Security Scan

Static application security testing (SAST) using [Semgrep](https://semgrep.dev/) with 8 community rulesets plus custom Moodle-specific rules.

**Community rulesets:**
| Ruleset | Coverage |
|---------|----------|
| `p/owasp-top-ten` | OWASP Top 10 2021 |
| `p/php` | PHP-specific security patterns |
| `p/security-audit` | General security audit |
| `p/command-injection` | OS command injection |
| `p/sql-injection` | SQL injection |
| `p/xss` | Cross-site scripting |
| `p/secrets` | Hardcoded secrets and credentials |
| `p/insecure-transport` | HTTP/TLS issues |

**Custom Moodle rules** (`.semgrep.yml`):
Standard Semgrep rules don't understand Moodle's framework patterns. The custom ruleset catches:
- Unsanitized HTML stored in `->content`, `->intro`, or `->summary` fields without `purify_html()`
- Variables echoed without `s()`, `format_text()`, or `html_writer`
- Direct `$_POST` access bypassing Moodle's `required_param()`/`optional_param()`
- Direct `$USER = get_admin()` instead of proper session management
- Base64 encoding used as a substitute for real encryption
- Stack traces stored in the database (information leakage)
- `require_course_login()` with guest auto-login enabled

### PHPStan Static Analysis

[PHPStan](https://phpstan.org/) at **level 6** — covers type safety, return types, undefined variables, dead code, and argument type validation.

The CI job checks out Moodle 4.5 stable, places the plugin inside it, and runs PHPStan with a bootstrap that loads Moodle's core classes for full type information.

**Running locally:**
```bash
composer install
vendor/bin/phpstan analyse --memory-limit=512M
```

### Moodle Coding Standards

[PHP CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) with the [moodlehq/moodle-cs](https://github.com/moodlehq/moodle-cs) ruleset enforces Moodle's coding standards — file docblocks, naming conventions, inline comment formatting, and PHPDoc annotations.

**Running locally:**
```bash
composer install
vendor/bin/phpcs --standard=moodle --extensions=php --ignore=vendor/,phpstan-bootstrap.php .
```

## Contributing

- **Branch protection** is enabled on `main` — all changes must go through a pull request with passing CI checks.
- Use the PR template and ensure all three checks pass (Semgrep, PHPStan, Moodle CS).
- Dependabot keeps composer and GitHub Actions dependencies up to date automatically.
- See [CHANGELOG.md](CHANGELOG.md) for release history.

## File Structure

```
local/githubsync/
  version.php                          # Plugin version and requirements
  lib.php                              # Navigation hooks and pluginfile handler
  config.php                           # Per-course configuration page
  sync.php                             # Sync trigger page
  webhook.php                          # GitHub webhook endpoint
  settings.php                         # Global admin settings
  LICENSE                              # GNU GPL v3
  SECURITY.md                          # Security architecture and audit history
  CHANGELOG.md                         # Release history
  .editorconfig                        # Editor formatting rules
  .semgrep.yml                         # Custom Moodle security rules for Semgrep
  phpstan.neon                         # PHPStan configuration
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
  .github/
    dependabot.yml                     # Automated dependency updates
    pull_request_template.md           # PR template
    ISSUE_TEMPLATE/
      bug_report.yml                   # Bug report form
      feature_request.yml              # Feature request form
    workflows/
      semgrep.yml                      # Semgrep OWASP security scan
      phpstan.yml                      # PHPStan static analysis
      moodle-cs.yml                    # Moodle coding standards
```

## License

This plugin is licensed under the [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.html), consistent with Moodle's license.
