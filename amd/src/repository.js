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
 * AJAX repository for the GitHub Sync file editor.
 *
 * @module     local_githubsync/repository
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    return {
        /**
         * Get the file tree for a course repository.
         *
         * @param {Number} courseid
         * @returns {Promise}
         */
        getFileTree: function(courseid) {
            return Ajax.call([{
                methodname: 'local_githubsync_get_file_tree',
                args: {courseid: courseid},
            }])[0];
        },

        /**
         * Get file content and SHA.
         *
         * @param {Number} courseid
         * @param {String} filepath
         * @returns {Promise}
         */
        getFileContent: function(courseid, filepath) {
            return Ajax.call([{
                methodname: 'local_githubsync_get_file_content',
                args: {courseid: courseid, filepath: filepath},
            }])[0];
        },

        /**
         * Update a file in the repository.
         *
         * @param {Number} courseid
         * @param {String} filepath
         * @param {String} content
         * @param {String} sha
         * @param {String} message
         * @returns {Promise}
         */
        updateFile: function(courseid, filepath, content, sha, message) {
            return Ajax.call([{
                methodname: 'local_githubsync_update_file',
                args: {
                    courseid: courseid,
                    filepath: filepath,
                    content: content,
                    sha: sha,
                    message: message,
                },
            }])[0];
        },
    };
});
