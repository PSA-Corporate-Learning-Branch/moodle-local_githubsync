# Changelog

All notable changes to the GitHub Sync for Moodle plugin are documented here.

## [0.5.0] - 2026-02-07

### Added
- LICENSE file (GPL v3).
- `.editorconfig` for consistent formatting across editors.
- Dependabot configuration for automated composer and GitHub Actions dependency updates.
- Pull request template with test plan checklist.
- Issue templates for bug reports and feature requests.
- CHANGELOG.md.

### Changed
- Branch protection on `main` requires all CI checks to pass.
- GitHub Release tagging for versioned releases.

## [0.4.0] - 2026-02-07

### Added
- Moodle coding standards CI workflow (`moodle-cs.yml`).
- PHPStan static analysis at level 6 with CI workflow (`phpstan.yml`).
- Semgrep OWASP security scanning with 10 custom Moodle-specific rules (`semgrep.yml`).
- SECURITY.md with full security architecture documentation.

### Changed
- All PHP files pass `phpcs --standard=moodle` with zero errors and warnings.
- File docblocks with `@copyright` and `@license` on all files.
- Member variables renamed from snake_case to camelCase per Moodle naming conventions.

### Security
- Webhook endpoint hardened with HMAC-SHA256 signature verification.
- All HTML content from GitHub sanitized through `purify_html()` before storage.
- PAT encryption requires Sodium — removed insecure base64 fallback.
- Legacy base64-encoded PATs auto-migrated to Sodium on first access.
- Asset type allowlist blocks SVG, PHP, and other dangerous file types.
- GitHub URL validation anchored to prevent path injection.
- PAT format validation (`ghp_*` / `github_pat_*`).
- Branch name validation restricts to safe characters.
- Generic error messages to users — detailed errors in internal logs only.
- Disabled guest auto-login for asset file access.
- URL-encoded API path components to prevent injection.
- Redacted stack traces from database log entries.

## [0.3.0] - 2026-02-06

### Added
- Asset handler for uploading CSS, JS, images, and fonts to Moodle file storage.
- Automatic URL rewriting of `assets/` references to `pluginfile.php` URLs.
- Delete/hide detection — activities removed from repo are hidden in Moodle.
- PAT encryption at rest using Moodle's Sodium encryption API.
- GitHub Compare API integration for incremental sync.
- Rate limit tracking from GitHub API response headers.
- `pluginfile.php` handler for serving stored assets.

## [0.2.0] - 2026-02-06

### Added
- Scheduled task for hourly auto-sync of configured courses.
- GitHub webhook endpoint for instant sync on push.
- CLI script for bulk syncing all configured courses.
- YAML front matter support for activity type control (page, label, URL).
- `course.yaml` support for updating course metadata on sync.
- `section.yaml` support for section titles and summaries.
- Content hash tracking for incremental updates.

## [0.1.0] - 2026-02-06

### Added
- Initial plugin skeleton with version, capabilities, and database schema.
- Per-course configuration form (repo URL, PAT, branch).
- GitHub REST API client for fetching repository content.
- Core sync engine that creates Moodle sections and Page activities from HTML files.
- Sync history logging with commit SHA tracking.
- Admin settings page with webhook URL display.
- Course settings navigation integration.
