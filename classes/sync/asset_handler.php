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

namespace local_githubsync\sync;

defined('MOODLE_INTERNAL') || die();

use local_githubsync\github\client;

/**
 * Handles uploading assets from a GitHub repo to Moodle file storage
 * and rewriting URLs in HTML content to point to the stored files.
 *
 * Assets are stored in a course-level file area:
 *   component: local_githubsync
 *   filearea:  assets
 *   itemid:    courseid
 *   filepath:  /subdir/ (matching repo structure under assets/)
 *   filename:  the file name
 *
 * A pluginfile handler in lib.php serves these files.
 */
class asset_handler {

    /** @var int Course ID */
    private int $courseid;

    /** @var \context_course Course context */
    private \context_course $context;

    /** @var client GitHub API client */
    private client $github;

    /** @var \file_storage Moodle file storage instance */
    private \file_storage $fs;

    /** @var array Log of asset operations */
    private array $operations = [];

    /** @var int Count of assets uploaded */
    private int $uploaded = 0;

    /** @var int Count of assets skipped (unchanged) */
    private int $skipped = 0;

    public function __construct(int $courseid, client $github) {
        $this->courseid = $courseid;
        $this->context = \context_course::instance($courseid);
        $this->github = $github;
        $this->fs = get_file_storage();
    }

    /**
     * Process all asset files from the repo tree.
     *
     * @param array $assetpaths Array of repo paths under assets/ (e.g. ['assets/css/custom.css', ...])
     * @return array Summary: ['uploaded' => int, 'skipped' => int, 'operations' => array]
     */
    public function process_assets(array $assetpaths): array {
        global $DB;

        foreach ($assetpaths as $repopath) {
            // Derive the storage path: assets/css/custom.css -> filepath=/css/, filename=custom.css
            $relpath = preg_replace('#^assets/#', '', $repopath);
            $dirname = dirname($relpath);
            $filename = basename($relpath);
            $filepath = ($dirname === '.' || $dirname === '') ? '/' : '/' . $dirname . '/';

            // Check if we already have this file with the same content hash.
            $mapping = $DB->get_record('local_githubsync_mapping', [
                'courseid' => $this->courseid,
                'repo_path' => $repopath,
            ]);

            // Fetch file content from GitHub.
            $content = $this->github->get_file_contents($repopath);
            $contenthash = sha1($content);

            if ($mapping && $mapping->content_hash === $contenthash) {
                $this->skipped++;
                $this->operations[] = ['type' => 'asset_skip', 'path' => $repopath, 'detail' => 'unchanged'];
                continue;
            }

            // Delete existing file if present (to replace with new version).
            $existingfile = $this->fs->get_file(
                $this->context->id,
                'local_githubsync',
                'assets',
                $this->courseid,
                $filepath,
                $filename
            );
            if ($existingfile) {
                $existingfile->delete();
            }

            // Store the new file.
            $filerecord = [
                'contextid' => $this->context->id,
                'component' => 'local_githubsync',
                'filearea' => 'assets',
                'itemid' => $this->courseid,
                'filepath' => $filepath,
                'filename' => $filename,
            ];

            $this->fs->create_file_from_string($filerecord, $content);

            // Update mapping.
            $now = time();
            if ($mapping) {
                $mapping->content_hash = $contenthash;
                $mapping->timemodified = $now;
                $DB->update_record('local_githubsync_mapping', $mapping);
            } else {
                $record = new \stdClass();
                $record->courseid = $this->courseid;
                $record->repo_path = $repopath;
                $record->cmid = null;
                $record->sectionid = null;
                $record->content_hash = $contenthash;
                $record->timecreated = $now;
                $record->timemodified = $now;
                $DB->insert_record('local_githubsync_mapping', $record);
            }

            $this->uploaded++;
            $this->operations[] = ['type' => 'asset_upload', 'path' => $repopath, 'detail' => $filepath . $filename];
        }

        return [
            'uploaded' => $this->uploaded,
            'skipped' => $this->skipped,
            'operations' => $this->operations,
        ];
    }

    /**
     * Rewrite asset URLs in HTML content.
     *
     * Converts relative paths like:
     *   assets/images/diagram.png
     *   ../assets/css/custom.css
     *   ../../assets/js/script.js
     *
     * To Moodle pluginfile URLs:
     *   /pluginfile.php/{contextid}/local_githubsync/assets/{courseid}/images/diagram.png
     *
     * @param string $html The HTML content
     * @return string HTML with rewritten asset URLs
     */
    public function rewrite_asset_urls(string $html): string {
        $baseurl = (string) \moodle_url::make_pluginfile_url(
            $this->context->id,
            'local_githubsync',
            'assets',
            $this->courseid,
            '/',
            ''
        );
        // Remove trailing filename placeholder.
        $baseurl = rtrim($baseurl, '/');

        // Rewrite src="assets/..." and href="assets/..." patterns.
        // Also handle ../assets/ and ../../assets/ relative paths.
        $html = preg_replace(
            '#((?:src|href)\s*=\s*["\'])(?:\.\.\/)*assets/#i',
            '$1' . $baseurl . '/',
            $html
        );

        return $html;
    }

    /**
     * Get the operations log.
     */
    public function get_operations(): array {
        return $this->operations;
    }
}
