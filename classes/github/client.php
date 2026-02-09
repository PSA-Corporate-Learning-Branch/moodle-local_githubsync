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

namespace local_githubsync\github;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * GitHub REST API client.
 *
 * Uses the GitHub REST API to fetch repository content without requiring
 * git to be installed on the Moodle server.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var string GitHub API base URL */
    private const API_BASE = 'https://api.github.com';

    /** @var string Repository owner */
    private string $owner;

    /** @var string Repository name */
    private string $repo;

    /** @var string Branch to sync from */
    private string $branch;

    /** @var string Personal Access Token */
    private string $pat;

    /** @var int Remaining API requests (from X-RateLimit-Remaining header) */
    private int $ratelimitremaining = -1;

    /** @var int Rate limit reset timestamp */
    private int $ratelimitreset = 0;

    /**
     * Constructor.
     *
     * @param string $repourl Full GitHub repository URL
     * @param string $pat Personal Access Token
     * @param string $branch Branch name
     */
    public function __construct(string $repourl, string $pat, string $branch = 'main') {
        $parsed = self::parse_repo_url($repourl);
        $this->owner = $parsed['owner'];
        $this->repo = $parsed['repo'];
        $this->pat = $pat;
        $this->branch = $branch;
    }

    /**
     * Parse a GitHub URL into owner and repo.
     *
     * @param string $url GitHub repository URL
     * @return array ['owner' => string, 'repo' => string]
     * @throws \moodle_exception If URL is invalid
     */
    public static function parse_repo_url(string $url): array {
        // Remove trailing slashes and .git suffix.
        $url = rtrim($url, '/');
        $url = preg_replace('/\.git$/', '', $url);

        if (preg_match('#^https://github\.com/([^/]+)/([^/]+)$#', $url, $matches)) {
            return ['owner' => $matches[1], 'repo' => $matches[2]];
        }

        throw new \moodle_exception('invalidrepourl', 'local_githubsync');
    }

    /**
     * Test the connection to the repository.
     *
     * @return bool True if accessible
     * @throws \moodle_exception If connection fails
     */
    public function test_connection(): bool {
        $response = $this->api_request($this->repo_endpoint());
        return !empty($response['id']);
    }

    /**
     * Get the latest commit SHA for the configured branch.
     *
     * @return string The commit SHA
     * @throws \moodle_exception If request fails
     */
    public function get_latest_commit_sha(): string {
        $response = $this->api_request($this->repo_endpoint('/commits/' . urlencode($this->branch)));
        return $response['sha'];
    }

    /**
     * Get the full repository tree (recursive).
     *
     * @return array Array of tree entries, each with 'path', 'type' ('blob' or 'tree'), 'sha', 'size'
     * @throws \moodle_exception If request fails
     */
    public function get_tree(): array {
        $response = $this->api_request(
            $this->repo_endpoint('/git/trees/' . urlencode($this->branch)),
            ['recursive' => '1']
        );

        if (empty($response['tree'])) {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'Empty repository tree');
        }

        return $response['tree'];
    }

    /**
     * Get the contents of a file from the repository.
     *
     * @param string $path File path within the repository
     * @return string Decoded file contents
     * @throws \moodle_exception If request fails
     */
    public function get_file_contents(string $path): string {
        // Encode each path segment individually to preserve directory separators.
        $encodedpath = implode('/', array_map('urlencode', explode('/', $path)));
        $response = $this->api_request(
            $this->repo_endpoint('/contents/' . $encodedpath),
            ['ref' => $this->branch]
        );

        if (empty($response['content'])) {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'Empty file');
        }

        // GitHub returns base64-encoded content.
        $content = base64_decode($response['content'], true);
        if (!is_string($content) || $content === '') {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'Failed to decode file');
        }

        return $content;
    }

    /**
     * Get the list of changed files between two commits.
     *
     * Uses the GitHub compare API for incremental sync.
     *
     * @param string $basesha The base commit SHA (last synced)
     * @return array Array of changed file entries, each with 'filename', 'status' (added/modified/removed/renamed)
     * @throws \moodle_exception If request fails
     */
    public function get_changed_files(string $basesha): array {
        $response = $this->api_request(
            $this->repo_endpoint('/compare/' . urlencode($basesha) . '...' . urlencode($this->branch))
        );

        if (!isset($response['files'])) {
            return [];
        }

        return $response['files'];
    }

    /**
     * Build a repo-scoped API endpoint path with URL-encoded owner/repo.
     *
     * @param string $suffix Additional path segments
     * @return string The full endpoint path
     */
    private function repo_endpoint(string $suffix = ''): string {
        return '/repos/' . urlencode($this->owner) . '/' . urlencode($this->repo) . $suffix;
    }

    /**
     * Get rate limit status.
     *
     * @return array ['remaining' => int, 'reset' => int]
     */
    public function get_rate_limit_status(): array {
        return [
            'remaining' => $this->ratelimitremaining,
            'reset' => $this->ratelimitreset,
        ];
    }

    /**
     * Get file contents including the blob SHA (needed for update operations).
     *
     * @param string $path File path within the repository
     * @return array ['content' => string, 'sha' => string, 'size' => int, 'name' => string, 'path' => string]
     * @throws \moodle_exception If request fails
     */
    public function get_file_content_with_sha(string $path): array {
        $encodedpath = implode('/', array_map('urlencode', explode('/', $path)));
        $response = $this->api_request(
            $this->repo_endpoint('/contents/' . $encodedpath),
            ['ref' => $this->branch]
        );

        if (empty($response['content']) && ($response['size'] ?? 0) > 0) {
            throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'File too large for Contents API');
        }

        $content = '';
        if (!empty($response['content'])) {
            $content = base64_decode($response['content'], true);
            if ($content === false) {
                throw new \moodle_exception('syncfailed', 'local_githubsync', '', 'Failed to decode file');
            }
        }

        return [
            'content' => $content,
            'sha' => $response['sha'],
            'size' => $response['size'] ?? strlen($content),
            'name' => $response['name'] ?? basename($path),
            'path' => $path,
        ];
    }

    /**
     * Update (or create) a file in the repository via the Contents API.
     *
     * @param string $path File path within the repository
     * @param string $content New file content (plain text)
     * @param string $sha Current blob SHA (for conflict detection)
     * @param string $message Commit message
     * @return array ['sha' => newBlobSha, 'commit_sha' => string, 'commit_message' => string]
     * @throws \moodle_exception If request fails
     */
    public function update_file(string $path, string $content, string $sha, string $message): array {
        $encodedpath = implode('/', array_map('urlencode', explode('/', $path)));
        $body = [
            'message' => $message,
            'content' => base64_encode($content),
            'sha' => $sha,
            'branch' => $this->branch,
        ];

        $response = $this->api_write_request('PUT', $this->repo_endpoint('/contents/' . $encodedpath), $body);

        return [
            'sha' => $response['content']['sha'] ?? '',
            'commit_sha' => $response['commit']['sha'] ?? '',
            'commit_message' => $response['commit']['message'] ?? $message,
        ];
    }

    /**
     * Make an authenticated write request (PUT/POST) to the GitHub API.
     *
     * @param string $method HTTP method (PUT or POST)
     * @param string $endpoint API endpoint path
     * @param array $body Request body (will be JSON-encoded)
     * @return array Decoded JSON response
     * @throws \moodle_exception If request fails
     */
    private function api_write_request(string $method, string $endpoint, array $body): array {
        $url = self::API_BASE . $endpoint;

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: token ' . $this->pat,
            'Accept: application/vnd.github.v3+json',
            'User-Agent: MoodleGitHubSync/1.0',
            'Content-Type: application/json',
        ]);

        $jsonbody = json_encode($body);

        if (strtoupper($method) === 'PUT') {
            $response = $curl->put($url, $jsonbody);
        } else {
            $response = $curl->post($url, $jsonbody);
        }

        if ($curl->get_errno()) {
            throw new \moodle_exception('connectionfailed', 'local_githubsync', '', $curl->error);
        }

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        // Track rate limit headers.
        $responseheaders = $curl->getResponse();
        if (isset($responseheaders['X-RateLimit-Remaining'])) {
            $this->ratelimitremaining = (int) $responseheaders['X-RateLimit-Remaining'];
        }
        if (isset($responseheaders['X-RateLimit-Reset'])) {
            $this->ratelimitreset = (int) $responseheaders['X-RateLimit-Reset'];
        }

        // Handle rate limiting.
        if ($httpcode === 403 && $this->ratelimitremaining === 0) {
            $resettime = userdate($this->ratelimitreset);
            throw new \moodle_exception(
                'connectionfailed',
                'local_githubsync',
                '',
                "GitHub API rate limit exceeded. Resets at {$resettime}"
            );
        }

        // Handle conflict (SHA mismatch).
        if ($httpcode === 409) {
            throw new \moodle_exception('editor_conflict', 'local_githubsync');
        }

        // Handle 403 permission denied (token lacks write scope).
        if ($httpcode === 403) {
            $decoded = json_decode($response, true);
            $message = $decoded['message'] ?? "HTTP 403";
            throw new \moodle_exception('editor_pat_noaccess', 'local_githubsync', '', $message);
        }

        if ($httpcode >= 400) {
            $decoded = json_decode($response, true);
            $message = $decoded['message'] ?? "HTTP {$httpcode}";
            throw new \moodle_exception('connectionfailed', 'local_githubsync', '', $message);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('connectionfailed', 'local_githubsync', '', 'Invalid JSON response');
        }

        return $decoded;
    }

    /**
     * Make an authenticated request to the GitHub API.
     *
     * @param string $endpoint API endpoint path (e.g. /repos/owner/repo)
     * @param array $params Query parameters
     * @return array Decoded JSON response
     * @throws \moodle_exception If request fails
     */
    private function api_request(string $endpoint, array $params = []): array {
        $url = self::API_BASE . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: token ' . $this->pat,
            'Accept: application/vnd.github.v3+json',
            'User-Agent: MoodleGitHubSync/1.0',
        ]);

        $response = $curl->get($url);

        if ($curl->get_errno()) {
            throw new \moodle_exception('connectionfailed', 'local_githubsync', '', $curl->error);
        }

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        // Track rate limit headers.
        $responseheaders = $curl->getResponse();
        if (isset($responseheaders['X-RateLimit-Remaining'])) {
            $this->ratelimitremaining = (int) $responseheaders['X-RateLimit-Remaining'];
        }
        if (isset($responseheaders['X-RateLimit-Reset'])) {
            $this->ratelimitreset = (int) $responseheaders['X-RateLimit-Reset'];
        }

        // Handle rate limiting.
        if ($httpcode === 403 && $this->ratelimitremaining === 0) {
            $resettime = userdate($this->ratelimitreset);
            throw new \moodle_exception(
                'connectionfailed',
                'local_githubsync',
                '',
                "GitHub API rate limit exceeded. Resets at {$resettime}"
            );
        }

        if ($httpcode >= 400) {
            $decoded = json_decode($response, true);
            $message = $decoded['message'] ?? "HTTP {$httpcode}";
            throw new \moodle_exception('connectionfailed', 'local_githubsync', '', $message);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('connectionfailed', 'local_githubsync', '', 'Invalid JSON response');
        }

        return $decoded;
    }
}
