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

namespace local_githubsync\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Configuration form for GitHub Sync course settings.
 *
 * @package    local_githubsync
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('header', 'general', get_string('config', 'local_githubsync'));

        $mform->addElement('text', 'repo_url', get_string('repo_url', 'local_githubsync'), ['size' => 60]);
        $mform->setType('repo_url', PARAM_URL);
        $mform->addRule('repo_url', null, 'required', null, 'client');
        $mform->addHelpButton('repo_url', 'repo_url', 'local_githubsync');

        $mform->addElement('password', 'pat', get_string('pat', 'local_githubsync'), ['size' => 60]);
        $mform->setType('pat', PARAM_RAW);

        $mform->addElement('text', 'branch', get_string('branch', 'local_githubsync'), ['size' => 30]);
        $mform->setType('branch', PARAM_TEXT);
        $mform->setDefault('branch', get_config('local_githubsync', 'default_branch') ?: 'main');

        $mform->addElement(
            'advcheckbox',
            'auto_sync',
            get_string('auto_sync', 'local_githubsync'),
            get_string('auto_sync_desc', 'local_githubsync')
        );

        // Show last sync info if available.
        if (!empty($this->_customdata['last_sync_time'])) {
            $lastsynced = userdate($this->_customdata['last_sync_time']);
            $sha = $this->_customdata['last_sync_sha'] ?? '';
            $mform->addElement(
                'static',
                'lastsyncinfo',
                get_string('lastsynced', 'local_githubsync'),
                $lastsynced . ($sha ? ' (commit ' . s($sha) . ')' : '')
            );
        }

        $this->add_action_buttons(true, get_string('savesettings', 'local_githubsync'));
    }

    /**
     * Form validation.
     *
     * @param array $data Form data
     * @param array $files Uploaded files
     * @return array Validation errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate GitHub URL format — anchored to prevent trailing path injection.
        if (!empty($data['repo_url'])) {
            $url = rtrim($data['repo_url'], '/');
            $url = preg_replace('/\.git$/', '', $url);
            if (!preg_match('#^https://github\.com/[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', $url)) {
                $errors['repo_url'] = get_string('invalidrepourl', 'local_githubsync');
            }
        }

        // Validate GitHub PAT format if provided.
        if (!empty($data['pat'])) {
            if (!preg_match('/^(ghp_[a-zA-Z0-9]{36,}|github_pat_[a-zA-Z0-9_]{22,})$/', $data['pat'])) {
                $errors['pat'] = get_string('invalidpat', 'local_githubsync');
            }
        }

        // Validate branch name — alphanumeric, hyphens, underscores, dots, slashes.
        if (!empty($data['branch'])) {
            if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $data['branch'])) {
                $errors['branch'] = get_string('invalidbranch', 'local_githubsync');
            }
        }

        return $errors;
    }
}
