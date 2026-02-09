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
 * Main editor logic for the GitHub Sync file editor.
 *
 * @module     local_githubsync/editor
 * @copyright  2026 Allan Haggett
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_githubsync/repository', 'core/notification', 'core/str'], function(Repository, Notification, Str) {

    /** @type {Object} Editor state */
    var state = {
        courseid: 0,
        currentPath: null,
        currentSha: null,
        originalContent: null,
        treeData: [],
        expandedDirs: {},
    };

    /** @type {Object} DOM element references */
    var dom = {};

    /**
     * Safely replace all children of an element.
     *
     * @param {HTMLElement} el
     * @param {Array<HTMLElement|String>} children - DOM nodes or strings (inserted as text)
     */
    var setChildren = function(el, children) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        children.forEach(function(child) {
            if (typeof child === 'string') {
                el.appendChild(document.createTextNode(child));
            } else {
                el.appendChild(child);
            }
        });
    };

    /**
     * Check if the editor has unsaved changes.
     *
     * @returns {Boolean}
     */
    var isDirty = function() {
        if (state.originalContent === null) {
            return false;
        }
        return dom.textarea.value !== state.originalContent;
    };

    /**
     * Update save button enabled state.
     */
    var updateSaveButton = function() {
        var hasMessage = dom.commitMessage.value.trim().length > 0;
        var dirty = isDirty();
        dom.saveBtn.disabled = !dirty || !hasMessage;
    };

    /**
     * Update the dirty indicator.
     */
    var checkDirty = function() {
        var dirty = isDirty();
        if (dirty) {
            dom.unsavedBadge.classList.remove('d-none');
        } else {
            dom.unsavedBadge.classList.add('d-none');
        }
        updateSaveButton();
    };

    /**
     * Build a <ul> element for a tree node.
     *
     * @param {Object} node
     * @param {String} pathPrefix
     * @returns {HTMLElement}
     */
    var buildTreeUl = function(node, pathPrefix) {
        var ul = document.createElement('ul');

        // Sort directories alphabetically.
        var dirNames = Object.keys(node.children).sort();

        dirNames.forEach(function(name) {
            var dirPath = pathPrefix ? pathPrefix + '/' + name : name;
            var li = document.createElement('li');
            li.className = 'githubsync-tree-dir';

            if (state.expandedDirs[dirPath]) {
                li.classList.add('expanded');
            }

            var span = document.createElement('span');
            span.className = 'githubsync-tree-item';
            span.textContent = name;
            span.addEventListener('click', function(e) {
                e.stopPropagation();
                li.classList.toggle('expanded');
                if (li.classList.contains('expanded')) {
                    state.expandedDirs[dirPath] = true;
                } else {
                    delete state.expandedDirs[dirPath];
                }
            });

            li.appendChild(span);
            li.appendChild(buildTreeUl(node.children[name], dirPath));
            ul.appendChild(li);
        });

        // Sort files alphabetically.
        var sortedFiles = node.files.slice().sort(function(a, b) {
            return a.name.localeCompare(b.name);
        });

        sortedFiles.forEach(function(file) {
            var li = document.createElement('li');
            li.className = 'githubsync-tree-file';

            if (file.isbinary) {
                li.classList.add('binary');
            }
            if (file.path === state.currentPath) {
                li.classList.add('active');
            }

            var span = document.createElement('span');
            span.className = 'githubsync-tree-item';
            span.textContent = file.name;

            if (!file.isbinary) {
                span.addEventListener('click', function(e) {
                    e.stopPropagation();
                    handleFileClick(file.path);
                });
            }

            li.appendChild(span);
            ul.appendChild(li);
        });

        return ul;
    };

    /**
     * Render the file tree from state.treeData.
     */
    var renderTree = function() {
        // Build a nested structure from the flat list.
        var root = {children: {}, files: []};

        state.treeData.forEach(function(item) {
            var parts = item.path.split('/');
            var node = root;

            if (item.type === 'dir') {
                parts.forEach(function(part) {
                    if (!node.children[part]) {
                        node.children[part] = {children: {}, files: []};
                    }
                    node = node.children[part];
                });
            } else {
                // Navigate to parent directory.
                var dirParts = parts.slice(0, -1);
                dirParts.forEach(function(part) {
                    if (!node.children[part]) {
                        node.children[part] = {children: {}, files: []};
                    }
                    node = node.children[part];
                });
                node.files.push(item);
            }
        });

        var ul = buildTreeUl(root, '');
        dom.tree.innerHTML = '';
        dom.tree.appendChild(ul);
    };

    /**
     * Load a file's content into the editor.
     *
     * @param {String} filepath
     * @returns {Promise}
     */
    var loadFile = function(filepath) {
        // Show loading state.
        dom.placeholder.classList.add('d-none');
        dom.editorContent.classList.remove('d-none');
        dom.textarea.value = '';
        dom.textarea.disabled = true;
        dom.filepath.textContent = filepath;
        dom.unsavedBadge.classList.add('d-none');
        dom.saveBtn.disabled = true;

        return Str.get_string('editor_loading', 'local_githubsync').then(function(loadingStr) {
            dom.status.textContent = loadingStr;
            return Repository.getFileContent(state.courseid, filepath);
        }).then(function(result) {
            state.currentPath = filepath;
            state.currentSha = result.sha;
            state.originalContent = result.content;
            dom.textarea.value = result.content;
            dom.textarea.disabled = false;
            dom.status.textContent = '';

            renderTree();
        }).catch(function(err) {
            Str.get_string('editor_loadfailed', 'local_githubsync').then(function(msg) {
                dom.status.textContent = msg;
            });
            Notification.exception(err);
        });
    };

    /**
     * Handle clicking on a file in the tree.
     *
     * @param {String} filepath
     */
    var handleFileClick = function(filepath) {
        if (filepath === state.currentPath) {
            return;
        }

        // Confirm if dirty.
        if (isDirty()) {
            Str.get_string('editor_unsaved_confirm', 'local_githubsync').then(function(msg) {
                if (window.confirm(msg)) {
                    loadFile(filepath);
                }
            });
            return;
        }

        loadFile(filepath);
    };

    /**
     * Load the file tree from the server.
     */
    var loadTree = function() {
        Str.get_string('editor_loading', 'local_githubsync').then(function(loadingStr) {
            var wrapper = document.createElement('div');
            wrapper.className = 'p-3 text-center text-muted';
            var spinner = document.createElement('div');
            spinner.className = 'spinner-border spinner-border-sm';
            spinner.setAttribute('role', 'status');
            wrapper.appendChild(spinner);
            wrapper.appendChild(document.createTextNode(' ' + loadingStr));
            setChildren(dom.tree, [wrapper]);

            return Repository.getFileTree(state.courseid);
        }).then(function(result) {
            state.treeData = result.files;
            renderTree();
        }).catch(function(err) {
            Str.get_string('editor_treefailed', 'local_githubsync').then(function(msg) {
                var errDiv = document.createElement('div');
                errDiv.className = 'p-3 text-danger';
                errDiv.textContent = msg;
                setChildren(dom.tree, [errDiv]);
            });
            Notification.exception(err);
        });
    };

    /**
     * Handle the save button click.
     */
    var handleSave = function() {
        var message = dom.commitMessage.value.trim();
        if (!message) {
            Str.get_string('editor_empty_message', 'local_githubsync').then(function(msg) {
                Notification.addNotification({message: msg, type: 'warning'});
            });
            return;
        }

        // Show saving state.
        dom.saveBtn.disabled = true;
        Str.get_string('editor_saving', 'local_githubsync').then(function(savingText) {
            dom.saveBtn.textContent = savingText;
            dom.status.textContent = savingText;

            return Repository.updateFile(
                state.courseid,
                state.currentPath,
                dom.textarea.value,
                state.currentSha,
                message
            );
        }).then(function(result) {
            if (result.conflict) {
                return Str.get_string('editor_conflict', 'local_githubsync').then(function(conflictMsg) {
                    Notification.addNotification({message: conflictMsg, type: 'error'});
                    return Str.get_string('editor_reload', 'local_githubsync');
                }).then(function(reloadMsg) {
                    var link = document.createElement('a');
                    link.href = '#';
                    link.textContent = reloadMsg;
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        loadFile(state.currentPath);
                    });
                    setChildren(dom.status, [link]);
                });
            } else if (result.success) {
                state.currentSha = result.newsha;
                state.originalContent = dom.textarea.value;
                dom.commitMessage.value = '';

                var shortsha = result.commitsha.substring(0, 7);
                return Str.get_string('editor_saved', 'local_githubsync', shortsha).then(function(savedMsg) {
                    Notification.addNotification({message: savedMsg, type: 'success'});
                    dom.status.textContent = '';
                    checkDirty();
                });
            }
        }).catch(function(err) {
            Notification.exception(err);
        }).then(function() {
            // Restore button regardless of outcome.
            Str.get_string('editor_save', 'local_githubsync').then(function(saveText) {
                dom.saveBtn.textContent = saveText;
                updateSaveButton();
            });
        });
    };

    return {
        /**
         * Initialise the editor.
         *
         * @param {Number} courseid
         */
        init: function(courseid) {
            state.courseid = courseid;

            // Cache DOM references.
            dom = {
                tree: document.getElementById('githubsync-file-tree'),
                filepath: document.getElementById('githubsync-filepath'),
                unsavedBadge: document.getElementById('githubsync-unsaved-badge'),
                placeholder: document.getElementById('githubsync-editor-placeholder'),
                editorContent: document.getElementById('githubsync-editor-content'),
                textarea: document.getElementById('githubsync-textarea'),
                commitMessage: document.getElementById('githubsync-commit-message'),
                saveBtn: document.getElementById('githubsync-save-btn'),
                refreshBtn: document.getElementById('githubsync-refresh'),
                status: document.getElementById('githubsync-status'),
            };

            // Bind events.
            dom.saveBtn.addEventListener('click', handleSave);
            dom.refreshBtn.addEventListener('click', function() {
                loadTree();
            });
            dom.textarea.addEventListener('input', checkDirty);
            dom.commitMessage.addEventListener('input', updateSaveButton);

            // Tab key inserts spaces.
            dom.textarea.addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var start = dom.textarea.selectionStart;
                    var end = dom.textarea.selectionEnd;
                    var value = dom.textarea.value;
                    dom.textarea.value = value.substring(0, start) + '    ' + value.substring(end);
                    dom.textarea.selectionStart = dom.textarea.selectionEnd = start + 4;
                    checkDirty();
                }
            });

            // Ctrl+S to save.
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    if (!dom.saveBtn.disabled) {
                        handleSave();
                    }
                }
            });

            // Unsaved changes warning.
            window.addEventListener('beforeunload', function(e) {
                if (isDirty()) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // Load the tree.
            loadTree();
        },
    };
});
