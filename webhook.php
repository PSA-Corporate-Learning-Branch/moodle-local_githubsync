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
 * GitHub webhook endpoint.
 *
 * Receives push events from GitHub and triggers a sync for the matching course.
 * Requires a webhook secret configured in Site Admin > Plugins > GitHub Sync.
 *
 * Configure in GitHub: Settings > Webhooks > Add webhook
 *   Payload URL: https://yourmoodle.com/local/githubsync/webhook.php
 *   Content type: application/json
 *   Secret: (must match the value in Moodle admin settings)
 *   Events: Just the push event
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

// Always return JSON.
header('Content-Type: application/json');

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Verify webhook secret via HMAC-SHA256 signature.
$secret = get_config('local_githubsync', 'webhook_secret');
if (empty($secret)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Webhook not configured']);
    exit;
}

$payload = file_get_contents('php://input');
if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit;
}

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (empty($signature)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Missing signature']);
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

$data = json_decode($payload, true);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Verify this is a push event.
$event = clean_param($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '', PARAM_ALPHANUMEXT);
if ($event === 'ping') {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($event !== 'push') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

// Extract and validate repository info from payload.
$repourl = is_string($data['repository']['html_url'] ?? null) ? $data['repository']['html_url'] : '';
$ref = is_string($data['ref'] ?? null) ? $data['ref'] : '';
$branch = '';
if (!empty($ref)) {
    $branch = preg_replace('#^refs/heads/#', '', $ref);
}

if (empty($repourl)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

// Normalize URL for lookup.
$normalizedurl = rtrim($repourl, '/');
$normalizedurl = preg_replace('/\.git$/', '', $normalizedurl);

// Query only matching configs instead of loading all.
$configs = $DB->get_records('local_githubsync_config', ['repo_url' => $normalizedurl]);

// Also try with trailing slash and .git variants.
if (empty($configs)) {
    $configs = $DB->get_records('local_githubsync_config', ['repo_url' => $normalizedurl . '.git']);
}

// Filter by branch if specified.
$matched = [];
foreach ($configs as $config) {
    if (!empty($branch) && $config->branch !== $branch) {
        continue;
    }
    $matched[] = $config;
}

if (empty($matched)) {
    // Return generic OK — do not reveal whether repo is configured.
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// Use admin user context for the sync.
\core\session\manager::set_user(get_admin());

$synced = 0;
foreach ($matched as $config) {
    try {
        $course = get_course($config->courseid);
        $engine = new \local_githubsync\sync\engine($course, $config);
        $result = $engine->execute();
        $engine->write_log($USER->id, $result['sha'], $result['status'], $result['summary']);
        $synced++;
    } catch (\Exception $e) {
        // Log internally only — do not expose details to caller.
        $log = new \stdClass();
        $log->courseid = $config->courseid;
        $log->userid = $USER->id;
        $log->commit_sha = '';
        $log->status = 'failed';
        $log->summary = 'Webhook sync failed';
        $log->details = json_encode(['error' => $e->getMessage()]);
        $log->timecreated = time();
        $DB->insert_record('local_githubsync_log', $log);
    }
}

// Generic response — do not leak course IDs, shortnames, or error details.
http_response_code(200);
echo json_encode(['status' => 'ok', 'synced' => $synced]);
