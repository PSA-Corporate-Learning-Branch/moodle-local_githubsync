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
 *
 * Configure in GitHub: Settings > Webhooks > Add webhook
 *   Payload URL: https://yourmoodle.com/local/githubsync/webhook.php
 *   Content type: application/json
 *   Events: Just the push event
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read the payload.
$payload = file_get_contents('php://input');
if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

$data = json_decode($payload, true);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Verify this is a push event.
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    http_response_code(200);
    echo json_encode(['message' => 'pong']);
    exit;
}

if ($event !== 'push') {
    http_response_code(200);
    echo json_encode(['message' => 'Ignored event: ' . $event]);
    exit;
}

// Extract repository info from payload.
$repourl = $data['repository']['html_url'] ?? '';
$branch = '';
if (!empty($data['ref'])) {
    // ref is like "refs/heads/main"
    $branch = preg_replace('#^refs/heads/#', '', $data['ref']);
}

if (empty($repourl)) {
    http_response_code(400);
    echo json_encode(['error' => 'No repository URL in payload']);
    exit;
}

// Find matching course configs.
$configs = $DB->get_records('local_githubsync_config');
$matched = [];

foreach ($configs as $config) {
    // Normalize URLs for comparison (strip trailing slashes and .git).
    $configurl = rtrim($config->repo_url, '/');
    $configurl = preg_replace('/\.git$/', '', $configurl);
    $payloadurl = rtrim($repourl, '/');
    $payloadurl = preg_replace('/\.git$/', '', $payloadurl);

    if (strcasecmp($configurl, $payloadurl) !== 0) {
        continue;
    }

    // If branch specified, only sync if it matches.
    if (!empty($branch) && $config->branch !== $branch) {
        continue;
    }

    $matched[] = $config;
}

if (empty($matched)) {
    http_response_code(200);
    echo json_encode(['message' => 'No matching course configuration found for ' . $repourl]);
    exit;
}

// Use admin user for the sync.
$USER = get_admin();

$results = [];
foreach ($matched as $config) {
    try {
        $course = get_course($config->courseid);
        $engine = new \local_githubsync\sync\engine($course, $config);
        $result = $engine->execute();
        $engine->write_log($USER->id, $result['sha'], $result['status'], $result['summary']);

        $results[] = [
            'courseid' => $config->courseid,
            'shortname' => $course->shortname,
            'status' => $result['status'],
            'summary' => $result['summary'],
        ];
    } catch (\Exception $e) {
        $results[] = [
            'courseid' => $config->courseid,
            'status' => 'failed',
            'error' => $e->getMessage(),
        ];
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['results' => $results]);
