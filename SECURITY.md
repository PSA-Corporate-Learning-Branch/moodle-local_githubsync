# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.4.x   | Yes       |
| < 0.4   | No        |

Version 0.4.0 includes significant security hardening. Earlier versions should be upgraded immediately.

## Reporting a Vulnerability

If you discover a security vulnerability in this plugin, please report it responsibly:

1. **Do not** open a public GitHub issue
2. Email the maintainer directly with details of the vulnerability
3. Include steps to reproduce and potential impact
4. Allow reasonable time for a fix before public disclosure

## Security Architecture

### Authentication and Access Control

- **Webhook endpoint** (`webhook.php`): Requires HMAC-SHA256 signature verification using a shared secret configured in Moodle admin settings. GitHub sends the signature in the `X-Hub-Signature-256` header. Requests without a valid signature are rejected with HTTP 403.
- **Configuration pages**: Protected by `require_login()` and `require_capability('local/githubsync:configure')` in the course context.
- **Sync trigger**: Protected by `require_login()`, `require_capability('local/githubsync:sync')`, and `require_sesskey()` (CSRF protection).
- **Asset files**: Served via `pluginfile.php` with `require_login()` — no guest access permitted.
- **Admin settings**: Protected by Moodle's `$hassiteconfig` check.
- **CLI scripts**: Only executable from the command line (`CLI_SCRIPT` define).

### Credential Storage

- GitHub Personal Access Tokens (PATs) are encrypted at rest using Moodle's Sodium-based encryption API (`\core\encryption`).
- Encryption keys must be configured in Moodle before PATs can be stored. There is no insecure fallback.
- Legacy base64-encoded PATs (from versions < 0.4) are automatically migrated to Sodium encryption on first access.
- PATs are never logged, displayed in the UI, or included in error messages.

### Input Sanitization

- **HTML content from GitHub** is passed through `purify_html()` (Moodle's HTMLPurifier wrapper) before storage. This strips `<script>` tags, event handlers, and other XSS vectors while preserving safe HTML.
- **Course metadata** from `course.yaml` is validated: shortnames are checked for uniqueness, course formats are validated against installed formats, summaries are purified.
- **Section summaries** from `section.yaml` are purified before storage.
- **Form inputs** are validated with strict patterns: repository URLs must match `github.com/owner/repo`, PATs must match GitHub's token format (`ghp_*` or `github_pat_*`), branch names are restricted to `[a-zA-Z0-9._/-]+`.
- **API URL paths** use `urlencode()` on all user-derived components (owner, repo, branch) to prevent path injection.

### Asset File Handling

- Only whitelisted file extensions are accepted: CSS, JS, images (PNG, JPG, GIF, WebP), fonts (WOFF, WOFF2, TTF), and common data formats.
- SVG files are blocked by default to prevent embedded JavaScript execution.
- PHP and other executable file types are blocked.
- Assets are served through Moodle's `send_stored_file()` which sets appropriate Content-Type headers.

### Error Handling

- Exception messages are logged internally but never exposed to end users. Users see generic error messages; administrators can view details in the sync log.
- Stack traces are not stored in the database.
- The webhook endpoint returns generic `{"status": "ok"}` responses regardless of outcome, preventing information disclosure about configured repositories or courses.

### CSRF Protection

- The sync trigger page requires a valid `sesskey`.
- The configuration form uses Moodle's `moodleform` class which includes automatic sesskey validation.
- The webhook endpoint uses HMAC signature verification instead of sesskey (appropriate for server-to-server communication).

## Automated Security Scanning

This project uses [Semgrep](https://semgrep.dev/) for automated static analysis security testing (SAST). The scan runs on every push and pull request to `main` via GitHub Actions.

### Rulesets

The scan includes these rulesets:

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
| `.semgrep.yml` | Custom Moodle-specific rules (see below) |

### Custom Moodle Rules

Standard Semgrep rules don't understand Moodle's framework patterns. We maintain custom rules in `.semgrep.yml` that catch:

- **Unescaped output**: Variables echoed without `s()`, `format_text()`, or `html_writer`
- **Unsanitized HTML storage**: Content stored in `->content`, `->intro`, or `->summary` fields without `purify_html()`
- **Direct `$_POST` access**: Bypassing Moodle's `required_param()`/`optional_param()` parameter cleaning
- **Unsafe admin elevation**: Direct `$USER = get_admin()` instead of `\core\session\manager::set_user()`
- **Base64 as encryption**: Using `base64_encode()` as a substitute for real encryption
- **Stack traces in storage**: `getTraceAsString()` which may expose sensitive information
- **Guest access to protected resources**: `require_course_login($course, true)` enabling guest auto-login

## Security Audit History

### v0.4.0 (2026-02-07) — Security Hardening Release

A comprehensive manual security audit identified and fixed 23 vulnerabilities:

| Severity | Count | Key Fixes |
|----------|-------|-----------|
| Critical | 3 | Webhook HMAC authentication, HTML purification, Sodium-only encryption |
| High | 5 | Generic error messages, session manager usage, info disclosure prevention |
| Medium | 8 | URL validation anchoring, asset type allowlist, guest access restriction, branch validation |
| Low | 7 | Output escaping, PAT format validation, Content-Type headers |

## Threat Model

### Trusted Inputs
- Moodle admin configuration (admin settings page)
- Authenticated users with `configure` or `sync` capabilities

### Untrusted Inputs
- **GitHub repository content** (HTML files, YAML files, asset files) — treated as potentially hostile even from configured repos, since repo contributors may not be Moodle admins
- **GitHub webhook payloads** — authenticated via HMAC, but payload content is still validated
- **GitHub API responses** — validated before use

### Out of Scope
- Moodle core vulnerabilities
- GitHub API security
- Server/network security
- Social engineering attacks on PAT holders
