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
 * AMD module for generating page/assignment/quiz/forum/lesson/glossary/book/url content drafts.
 *
 * All flows use a preview modal before applying content:
 * - Page: options -> generate -> preview modal -> apply to editor
 * - Assignment: options -> generate -> preview modal -> apply to editor
 * - Quiz: options -> generate -> preview modal (with checkboxes) -> add selected to quiz
 * - Forum: options -> generate -> preview modal -> apply introduction to editor
 * - Lesson: options -> generate -> preview modal -> apply to editor
 * - Glossary: options -> generate -> preview modal -> apply to editor
 * - Book: options -> generate -> preview modal -> apply to editor
 * - URL: options -> generate -> preview modal -> select URL to apply
 *
 * @module     block_mastermind_assistant/mod_draft
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* global tinyMCE */

define(['core/ajax', 'core/notification', 'block_mastermind_assistant/ai_policy', 'core/str', 'core/templates'],
function(Ajax, Notification, AiPolicy, Str, Templates) {

    var courseid = 0;
    var lastAssignmentResponse = null;
    var lastPageResponse = null;
    var lastQuizResponse = null;
    var lastForumResponse = null;
    var lastLessonResponse = null;
    var lastGlossaryResponse = null;
    var lastBookResponse = null;
    var lastUrlResponse = null;

    // ─── Shared helpers ──────────────────────────────────────────────────

    /**
     * Log user feedback (apply/regenerate/discard) for training data.
     * Non-blocking — errors are silently ignored to avoid disrupting the UI.
     * @param {string} action 'apply' | 'regenerate' | 'discard'
     * @param {string} moduletype e.g. 'page', 'quiz', 'assign', 'forum', 'lesson', 'glossary', 'book', 'url'
     */
    function logFeedback(action, moduletype) {
        var btn = document.getElementById('generate-draft-btn') || document.getElementById('generate-questions-btn');
        var coursename = btn ? (btn.getAttribute('data-coursename') || '') : '';
        var activityname = '';
        var nameField = document.getElementById('id_name');
        if (nameField) {
            activityname = nameField.value || '';
        }
        Ajax.call([{
            methodname: 'block_mastermind_assistant_log_generation_feedback',
            args: {
                courseid: courseid,
                action: action,
                moduletype: moduletype,
                activityname: activityname,
                coursename: coursename
            }
        }])[0].catch(function() {
            // Silent — feedback logging is non-critical.
        });
    }

    /**
     *
     */
    function showProgress() {
        var el = document.getElementById('mod-draft-progress-container');
        if (el) {
            el.style.display = 'block';
        }
    }

    /**
     *
     */
    function hideProgress() {
        var el = document.getElementById('mod-draft-progress-container');
        if (el) {
            el.style.display = 'none';
        }
    }

    /**
     *
     * @param {string} modname
     */
    function showCompletedState(modname) {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (!introDiv) {
            return;
        }
        var mn = modname ? modname.toLowerCase() : '';
        var stringMap = {
            'quiz': 'success_questions_added',
            'assign': 'success_instructions_applied',
            'forum': 'success_forum_applied',
            'lesson': 'success_lesson_applied',
            'glossary': 'success_glossary_applied',
            'book': 'success_book_applied',
            'url': 'success_url_applied'
        };
        var stringKey = stringMap[mn] || 'success_content_applied';
        Str.get_string(stringKey, 'block_mastermind_assistant').then(function(message) {
            return Templates.renderForPromise('block_mastermind_assistant/success_state', {message: message});
        }).then(function(result) {
            introDiv.innerHTML = result.html;
            introDiv.style.display = 'block';
            return null;
        }).catch(function() {
            // Fallback.
        });
    }

    /**
     *
     * @param {HTMLElement} btn
     */
    function readFormValues(btn) {
        var pagename = '';
        var pagedescription = '';

        var nameField = document.getElementById('id_name');
        if (nameField) {
            pagename = nameField.value.trim();
        }
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('id_introeditor')) {
            pagedescription = tinyMCE.get('id_introeditor').getContent();
        }
        if (!pagename) {
            pagename = btn.getAttribute('data-pagename') || btn.getAttribute('data-coursename') || '';
        }
        if (!pagedescription) {
            pagedescription = btn.getAttribute('data-pagedescription') || '';
        }
        return {name: pagename, description: pagedescription};
    }

    /**
     *
     * @param {string} value
     */
    function parseJSON(value) {
        if (!value) {
            return [];
        }
        if (Array.isArray(value)) {
            return value;
        }
        if (typeof value === 'string') {
            try {
                var parsed = JSON.parse(value);
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }
        return [];
    }

    /**
     *
     * @param {string} str
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     *
     * @param {HTMLElement} modal
     */
    function moveToBody(modal) {
        if (modal && modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
    }

    // ─── Editor apply (shared by all module types) ─────────────────────

    /**
     * All known editor textarea IDs across module types.
     * id_page = Page content, id_activityeditor = Assignment,
     * id_introeditor = intro/description for all modules.
     */
    var EDITOR_IDS = [
        'id_page', 'id_activityeditor', 'id_introeditor',
        'id_page_editor', 'id_pageeditor',
        'id_content_editor', 'id_contenteditor'
    ];

    /**
     * Mark form as changed so Moodle warns on navigation.
     */
    function markFormChanged() {
        if (typeof M !== 'undefined' && M.core_formchangechecker) {
            M.core_formchangechecker.set_form_changed();
        }
    }

    /**
     * Try to apply content to the Atto editor. Returns true if
     * successful.
     *
     * @param {string} content
     * @param {string|null} targetId
     * @returns {boolean}
     */
    function tryAttoEditor(content, targetId) {
        var selector = targetId
            ? '#' + targetId + 'editable'
            : null;
        var attoEl = null;

        // Atto wraps the textarea in a contenteditable div with
        // id = "<textarea_id>editable".
        if (selector) {
            attoEl = document.querySelector(selector);
        }

        // If specific lookup failed, try any visible Atto editor.
        if (!attoEl) {
            var allAtto = document.querySelectorAll(
                '.editor_atto_content[contenteditable="true"]'
            );
            for (var i = 0; i < allAtto.length; i++) {
                if (allAtto[i].offsetParent !== null) {
                    attoEl = allAtto[i];
                    break;
                }
            }
        }

        if (attoEl) {
            attoEl.innerHTML = content;
            attoEl.dispatchEvent(
                new Event('input', {bubbles: true})
            );
            // Also update the hidden textarea so the form
            // submits the new value.
            var attoWrap = attoEl.closest('.editor_atto_wrap');
            if (attoWrap) {
                var ta = attoWrap.querySelector('textarea');
                if (ta) {
                    ta.value = content;
                }
            }
            markFormChanged();
            attoEl.scrollIntoView(
                {behavior: 'smooth', block: 'center'}
            );
            return true;
        }
        return false;
    }

    /**
     * Try to apply content via TinyMCE. Returns true if
     * successful.
     *
     * @param {string} content
     * @returns {boolean}
     */
    function tryTinyMCE(content) {
        if (typeof tinyMCE === 'undefined') {
            return false;
        }
        // Try known IDs first.
        for (var i = 0; i < EDITOR_IDS.length; i++) {
            var editor = tinyMCE.get(EDITOR_IDS[i]);
            if (editor) {
                editor.setContent(content);
                editor.save();
                markFormChanged();
                var container = editor.getContainer();
                if (container) {
                    container.scrollIntoView(
                        {behavior: 'smooth', block: 'center'}
                    );
                }
                return true;
            }
        }
        // Fallback: first available TinyMCE instance.
        var editors = tinyMCE.editors;
        if (editors && editors.length > 0) {
            editors[0].setContent(content);
            editors[0].save();
            markFormChanged();
            var c = editors[0].getContainer();
            if (c) {
                c.scrollIntoView(
                    {behavior: 'smooth', block: 'center'}
                );
            }
            return true;
        }
        return false;
    }

    /**
     * Try to apply content to a plain textarea. Returns true if
     * successful.
     *
     * @param {string} content
     * @returns {boolean}
     */
    function tryTextarea(content) {
        for (var j = 0; j < EDITOR_IDS.length; j++) {
            var textarea = document.getElementById(EDITOR_IDS[j]);
            if (textarea && textarea.tagName === 'TEXTAREA') {
                textarea.value = content;
                textarea.dispatchEvent(
                    new Event('change', {bubbles: true})
                );
                markFormChanged();
                textarea.scrollIntoView(
                    {behavior: 'smooth', block: 'center'}
                );
                return true;
            }
        }
        return false;
    }

    /**
     * Apply HTML content to the best available editor on the
     * page. Tries Atto first (Moodle default), then TinyMCE,
     * then plain textarea.
     *
     * @param {string} content  HTML content to apply
     * @param {string} [targetId]  Optional specific textarea ID
     * @returns {boolean} true if content was applied
     */
    function applyContentToEditor(content, targetId) {
        // 1. Try Atto (Moodle default editor).
        if (tryAttoEditor(content, targetId || null)) {
            return true;
        }

        // 2. Try TinyMCE.
        if (tryTinyMCE(content)) {
            return true;
        }

        // 3. Try plain textarea fallback.
        if (tryTextarea(content)) {
            return true;
        }

        // Nothing worked.
        Notification.addNotification({
            message: 'Could not find an editor on this page. ' +
                'Please paste the content manually.',
            type: 'warning'
        });
        return false;
    }

    // ─── Page generation (with preview) ──────────────────────────────────

    /**
     *
     */
    function resetPageUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-draft-btn');
        var optionsPanel = document.getElementById('page-options-panel');
        if (introDiv) {
            introDiv.style.display = 'block';
        }
        if (btn) {
            btn.style.display = 'block';
        }
        if (optionsPanel) {
            optionsPanel.style.display = 'flex';
        }
    }

    /**
     *
     * @param {string} coursename
     * @param {string} pagename
     * @param {string} pagedescription
     */
    function generatePageDraft(coursename, pagename, pagedescription) {
        var btn = document.getElementById('generate-draft-btn');
        if (!btn) {
            return;
        }
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
            introDiv.style.display = 'none';
        }
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('page-options-panel');
        if (optionsPanel) {
            optionsPanel.style.display = 'none';
        }
        showProgress();

        var typeSelect = document.getElementById('page-content-type-select');
        var levelSelect = document.getElementById('page-level-select');
        var lengthSelect = document.getElementById('page-length-select');
        var sectionSelect = document.getElementById('id_section');

        var args = {
            courseid: courseid,
            coursename: coursename,
            pagename: pagename,
            pagedescription: pagedescription,
            contenttype: typeSelect ? typeSelect.value : '',
            academiclevel: levelSelect ? levelSelect.value : '',
            targetlength: lengthSelect ? lengthSelect.value : '',
            sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
            courseactivities: '[]'
        };

        logFeedback('generate', 'page');
        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_page_content',
            args: args
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    lastPageResponse = response;
                    showPagePreviewModal(response);
                } else {
                    Notification.addNotification({
                        message: response.message || 'Error generating content',
                        type: 'error'
                    });
                    resetPageUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({
                    message: 'Error: ' + (error.message || 'Connection error'),
                    type: 'error'
                });
                resetPageUI();
            });
    }

    /**
     *
     * @param {Object} response
     */
    function showPagePreviewModal(response) {
        var modal = document.getElementById('page-preview-modal');
        if (!modal) {
            return;
        }
        moveToBody(modal);

        var contentEl = document.getElementById('page-preview-content');
        if (contentEl) {
            contentEl.innerHTML = response.content || '';
        }

        var titleSection = document.getElementById('page-preview-title-section');
        var titleText = document.getElementById('page-preview-title-text');
        if (titleSection && titleText) {
            if (response.title) {
                titleText.textContent = response.title;
                titleSection.style.display = 'block';
            } else {
                titleSection.style.display = 'none';
            }
        }

        var readingTimeSection = document.getElementById('page-preview-reading-time');
        var readingTimeText = document.getElementById('page-preview-reading-time-text');
        if (readingTimeSection && readingTimeText) {
            if (response.estimated_reading_time) {
                readingTimeText.textContent = response.estimated_reading_time;
                readingTimeSection.style.display = 'block';
            } else {
                readingTimeSection.style.display = 'none';
            }
        }

        var summarySection = document.getElementById('page-preview-summary-section');
        var summaryText = document.getElementById('page-preview-summary-text');
        if (summarySection && summaryText) {
            if (response.content_summary) {
                summaryText.textContent = response.content_summary;
                summarySection.style.display = 'block';
            } else {
                summarySection.style.display = 'none';
            }
        }

        var objSection = document.getElementById('page-preview-objectives-section');
        var objList = document.getElementById('page-preview-objectives-list');
        if (objSection && objList) {
            var objectives = parseJSON(response.learning_objectives);
            if (objectives.length) {
                objList.innerHTML = objectives.map(function(o) {
                    return '<li>' + escapeHtml(o) + '</li>';
                }).join('');
                objSection.style.display = 'block';
            } else {
                objSection.style.display = 'none';
            }
        }

        var conceptsSection = document.getElementById('page-preview-concepts-section');
        var conceptsList = document.getElementById('page-preview-concepts-list');
        if (conceptsSection && conceptsList) {
            var concepts = parseJSON(response.key_concepts);
            if (concepts.length) {
                conceptsList.innerHTML = concepts.map(function(c) {
                    return '<li>' + escapeHtml(c) + '</li>';
                }).join('');
                conceptsSection.style.display = 'block';
            } else {
                conceptsSection.style.display = 'none';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closePagePreviewModal() {
        var modal = document.getElementById('page-preview-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    /**
     *
     */
    function applyPageContent() {
        if (!lastPageResponse) {
            return;
        }
        var applied = applyContentToEditor(
            lastPageResponse.content || '', 'id_page'
        );
        if (applied) {
            closePagePreviewModal();
            showCompletedState('page');
            Notification.addNotification({
                message: 'Content applied successfully!',
                type: 'success'
            });
        }
    }

    // ─── Assignment generation (with preview) ────────────────────────────

    /**
     *
     */
    function resetAssignmentUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-draft-btn');
        var optionsPanel = document.getElementById('assign-options-panel');
        if (introDiv) {
            introDiv.style.display = 'block';
        }
        if (btn) {
            btn.style.display = 'block';
        }
        if (optionsPanel) {
            optionsPanel.style.display = 'flex';
        }
    }

    /**
     *
     * @param {string} coursename
     * @param {string} assignmentname
     * @param {string} assignmentdescription
     */
    function generateAssignmentDraft(coursename, assignmentname, assignmentdescription) {
        var btn = document.getElementById('generate-draft-btn');
        if (!btn) {
            return;
        }

        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
            introDiv.style.display = 'none';
        }
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('assign-options-panel');
        if (optionsPanel) {
            optionsPanel.style.display = 'none';
        }
        showProgress();

        var typeSelect = document.getElementById('assign-type-select');
        var levelSelect = document.getElementById('assign-level-select');
        var scopeInput = document.getElementById('assign-scope-input');
        var sectionSelect = document.getElementById('id_section');

        var args = {
            courseid: courseid,
            coursename: coursename,
            assignmentname: assignmentname,
            assignmentdescription: assignmentdescription,
            assignmenttype: typeSelect ? typeSelect.value : '',
            academiclevel: levelSelect ? levelSelect.value : '',
            scopelength: scopeInput ? scopeInput.value.trim() : '',
            sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
            courseactivities: '[]'
        };

        logFeedback('generate', 'assign');
        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_assignment_instructions',
            args: args
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    lastAssignmentResponse = response;
                    showAssignPreviewModal(response);
                } else {
                    Notification.addNotification({
                        message: response.message || 'Error generating instructions',
                        type: 'error'
                    });
                    resetAssignmentUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({
                    message: 'Error: ' + (error.message || 'Connection error'),
                    type: 'error'
                });
                resetAssignmentUI();
            });
    }

    /**
     *
     * @param {Object} response
     */
    function showAssignPreviewModal(response) {
        var modal = document.getElementById('assign-preview-modal');
        if (!modal) {
            return;
        }
        moveToBody(modal);

        var instructionsEl = document.getElementById('preview-instructions-content');
        if (instructionsEl) {
            instructionsEl.innerHTML = response.content || '';
        }

        var titleSection = document.getElementById('preview-suggested-title');
        var titleText = document.getElementById('preview-title-text');
        if (titleSection && titleText) {
            if (response.title) {
                titleText.textContent = response.title;
                titleSection.style.display = 'block';
            } else {
                titleSection.style.display = 'none';
            }
        }

        var timeSection = document.getElementById('preview-time-section');
        var timeText = document.getElementById('preview-time-text');
        if (timeSection && timeText) {
            if (response.estimated_time) {
                timeText.textContent = response.estimated_time;
                timeSection.style.display = 'block';
            } else {
                timeSection.style.display = 'none';
            }
        }

        var reqSection = document.getElementById('preview-requirements-section');
        var reqList = document.getElementById('preview-requirements-list');
        if (reqSection && reqList) {
            var requirements = parseJSON(response.key_requirements);
            if (requirements.length) {
                reqList.innerHTML = requirements.map(function(r) {
                    return '<li>' + escapeHtml(r) + '</li>';
                }).join('');
                reqSection.style.display = 'block';
            } else {
                reqSection.style.display = 'none';
            }
        }

        var outSection = document.getElementById('preview-outcomes-section');
        var outList = document.getElementById('preview-outcomes-list');
        if (outSection && outList) {
            var outcomes = parseJSON(response.learning_outcomes);
            if (outcomes.length) {
                outList.innerHTML = outcomes.map(function(o) {
                    return '<li>' + escapeHtml(o) + '</li>';
                }).join('');
                outSection.style.display = 'block';
            } else {
                outSection.style.display = 'none';
            }
        }

        var rubricSection = document.getElementById('preview-rubric-section');
        var rubricContent = document.getElementById('preview-rubric-content');
        if (rubricSection && rubricContent) {
            var rubric = parseJSON(response.rubric_criteria);
            if (rubric.length) {
                rubricContent.innerHTML = buildRubricTable(rubric);
                rubricSection.style.display = 'block';
            } else {
                rubricSection.style.display = 'none';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closeAssignPreviewModal() {
        var modal = document.getElementById('assign-preview-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    /**
     *
     * @param {Object} criteria
     */
    function buildRubricTable(criteria) {
        if (!criteria.length) {
            return '';
        }
        var levels = criteria[0].levels || [];

        var html = '<table class="mastermind-rubric-table"><thead><tr>';
        html += '<th>Criterion</th><th>Weight</th>';
        for (var h = 0; h < levels.length; h++) {
            html += '<th>' + escapeHtml(levels[h].label) + '</th>';
        }
        html += '</tr></thead><tbody>';

        for (var i = 0; i < criteria.length; i++) {
            var c = criteria[i];
            html += '<tr>';
            html += '<td class="mastermind-rubric-criterion">' + escapeHtml(c.criterion) + '</td>';
            html += '<td class="mastermind-rubric-weight">' + escapeHtml(c.weight) + '</td>';
            var cLevels = c.levels || [];
            for (var j = 0; j < cLevels.length; j++) {
                html += '<td>' + escapeHtml(cLevels[j].description) + '</td>';
            }
            html += '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    /**
     *
     */
    function applyAssignmentContent() {
        if (!lastAssignmentResponse) {
            return;
        }

        var content = lastAssignmentResponse.content || '';

        var outcomes = parseJSON(lastAssignmentResponse.learning_outcomes);
        if (outcomes.length) {
            content += '<h3>Learning Outcomes</h3><ul>';
            for (var i = 0; i < outcomes.length; i++) {
                content += '<li>' + outcomes[i] + '</li>';
            }
            content += '</ul>';
        }

        var requirements = parseJSON(lastAssignmentResponse.key_requirements);
        if (requirements.length) {
            content += '<h3>Key Requirements</h3><ul>';
            for (var j = 0; j < requirements.length; j++) {
                content += '<li>' + requirements[j] + '</li>';
            }
            content += '</ul>';
        }

        if (lastAssignmentResponse.estimated_time) {
            content += '<p><strong>Estimated Time:</strong> ' +
                lastAssignmentResponse.estimated_time + '</p>';
        }

        var applied = applyContentToEditor(
            content, 'id_activityeditor'
        );
        if (applied) {
            closeAssignPreviewModal();
            showCompletedState('assign');
            Notification.addNotification({
                message: 'Instructions applied successfully!',
                type: 'success'
            });
        }
    }

    // ─── Quiz generation (with preview + selection) ──────────────────────

    /**
     *
     */
    function resetQuizUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-questions-btn');
        var optionsPanel = document.getElementById('quiz-options-panel');
        if (introDiv) {
            introDiv.style.display = 'block';
        }
        if (btn) {
            btn.style.display = 'block';
        }
        if (optionsPanel) {
            optionsPanel.style.display = 'flex';
        }
    }

    /**
     *
     */
    /**
     * Read a File from the upload input and resolve to a base64-encoded string
     * (without the data URL prefix).
     * @param {File} file
     * @return {Promise<string>}
     */
    function readFileAsBase64(file) {
        // eslint-disable-next-line promise/avoid-new, promise/no-native
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() {
                var result = reader.result || '';
                var commaIdx = result.indexOf(',');
                resolve(commaIdx >= 0 ? result.substring(commaIdx + 1) : result);
            };
            reader.onerror = function() {
                reject(reader.error || new Error('Failed to read file'));
            };
            reader.readAsDataURL(file);
        });
    }

    /** Kick off quiz question generation, optionally from an uploaded document. */
    function generateQuizDraft() {
        var btn = document.getElementById('generate-questions-btn');
        if (!btn) {
            return;
        }
        var fileInput = document.getElementById('quiz-source-doc');
        var sourceFile = (fileInput && fileInput.files && fileInput.files[0]) || null;
        if (sourceFile) {
            var maxBytes = 10 * 1024 * 1024;
            if (sourceFile.size > maxBytes) {
                Notification.addNotification({
                    message: 'File exceeds maximum size of 10MB.',
                    type: 'error'
                });
                return;
            }
        }
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
            introDiv.style.display = 'none';
        }
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('quiz-options-panel');
        if (optionsPanel) {
            optionsPanel.style.display = 'none';
        }
        showProgress();
        var quizid = btn.getAttribute('data-quizid');
        var formValues = readFormValues(btn);
        var quizname = formValues.name || btn.getAttribute('data-quizname') || 'Quiz';
        var quizdescription = formValues.description || btn.getAttribute('data-quizdescription') || '';
        var difficultySelect = document.getElementById('quiz-difficulty-select');
        var countSelect = document.getElementById('quiz-count-select');
        var levelSelect = document.getElementById('quiz-level-select');
        var sectionSelect = document.getElementById('id_section');
        var baseArgs = {
            courseid: courseid,
            quizid: parseInt(quizid, 10),
            quizname: quizname,
            quizdescription: quizdescription,
            difficultylevel: difficultySelect ? difficultySelect.value : '',
            questioncount: countSelect ? parseInt(countSelect.value, 10) : 8,
            academiclevel: levelSelect ? levelSelect.value : '',
            sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
            courseactivities: '[]',
            previewonly: true,
            selectedquestions: '',
            filedata: '',
            filetype: '',
            filename: ''
        };
        var argsPromise;
        if (sourceFile) {
            argsPromise = readFileAsBase64(sourceFile).then(function(b64) {
                baseArgs.filedata = b64;
                baseArgs.filetype = sourceFile.type || 'application/octet-stream';
                baseArgs.filename = sourceFile.name;
                return baseArgs;
            });
        } else {
            // eslint-disable-next-line promise/no-native
            argsPromise = Promise.resolve(baseArgs);
        }
        logFeedback('generate', 'quiz');
        argsPromise
            .then(function(args) {
                return Ajax.call([{
                    methodname: 'block_mastermind_assistant_generate_quiz_questions',
                    args: args
                }])[0];
            })
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    var questions = parseJSON(response.questions);
                    lastQuizResponse = questions;
                    showQuizPreviewModal(questions);
                } else {
                    Notification.addNotification({
                        message: response.message || 'Error generating questions',
                        type: 'error'
                    });
                    resetQuizUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({
                    message: 'Error: ' + (error.message || 'Connection error'),
                    type: 'error'
                });
                resetQuizUI();
            });
    }
    var TYPE_LABELS = {
        multichoice: 'Multiple Choice',
        truefalse: 'True/False',
        shortanswer: 'Short Answer',
        match: 'Matching',
        gapselect: 'Select Missing Words',
        numerical: 'Numerical'
    };
    /**
     *
     * @param {Object} question
     * @param {number} index
     */
    // eslint-disable-next-line complexity
    function renderQuestionCard(question, index) {
        var typeLabel = TYPE_LABELS[question.type] || question.type;
        var typeClass = 'mastermind-quiz-type-' + question.type;

        var html = '<div class="mastermind-quiz-question-card selected" data-index="' + index + '">';
        html += '<div class="mastermind-quiz-question-header">';
        html += '<label class="mastermind-quiz-checkbox-label">';
        html += '<input type="checkbox" class="mastermind-quiz-checkbox" data-index="' + index + '" checked />';
        html += '<span class="mastermind-quiz-checkbox-custom"></span>';
        html += '</label>';
        html += '<span class="mastermind-quiz-type-badge ' + typeClass + '">' + escapeHtml(typeLabel) + '</span>';
        html += '<span class="mastermind-quiz-question-name">' + escapeHtml(question.name || '') + '</span>';
        html += '</div>';

        html += '<div class="mastermind-quiz-question-body">';

        // Question text (strip HTML for preview).
        var questionText = (question.questiontext || '').replace(/<[^>]*>/g, '');
        html += '<p class="mastermind-quiz-question-text">' + escapeHtml(questionText) + '</p>';

        // Render answers based on type.
        if (question.type === 'multichoice' && question.answers) {
            html += '<ul class="mastermind-quiz-answers">';
            var letters = ['A', 'B', 'C', 'D', 'E', 'F'];
            for (var a = 0; a < question.answers.length; a++) {
                var ans = question.answers[a];
                var isCorrect = ans.fraction > 0;
                html += '<li class="' + (isCorrect ? 'mastermind-quiz-answer-correct' : '') + '">';
                html += '<span class="mastermind-quiz-answer-letter">' + (letters[a] || '') + '</span> ';
                html += escapeHtml(ans.answer);
                if (isCorrect) {
                    html += ' <span class="mastermind-quiz-correct-mark">&#10003;</span>';
                }
                html += '</li>';
            }
            html += '</ul>';

        } else if (question.type === 'truefalse' && question.answers) {
            html += '<div class="mastermind-quiz-tf-options">';
            for (var t = 0; t < question.answers.length; t++) {
                var tfAns = question.answers[t];
                var tfCorrect = tfAns.fraction > 0;
                html += '<span class="mastermind-quiz-tf-option ' +
                    (tfCorrect ? 'mastermind-quiz-answer-correct' : '') + '">';
                html += escapeHtml(tfAns.answer);
                if (tfCorrect) {
                    html += ' <span class="mastermind-quiz-correct-mark">&#10003;</span>';
                }
                html += '</span>';
            }
            html += '</div>';

        } else if (question.type === 'shortanswer' && question.answers) {
            html += '<div class="mastermind-quiz-short-answers">';
            html += '<span class="mastermind-quiz-sa-label">Accepted: </span>';
            var saWords = question.answers.map(function(sa) {
                return escapeHtml(sa.answer);
            });
            html += '<span class="mastermind-quiz-sa-words">' + saWords.join(', ') + '</span>';
            html += '</div>';

        } else if (question.type === 'match' && question.subquestions) {
            html += '<table class="mastermind-quiz-match-table">';
            for (var m = 0; m < question.subquestions.length; m++) {
                var sq = question.subquestions[m];
                html += '<tr>';
                html += '<td class="mastermind-quiz-match-stem">' + escapeHtml(sq.question) + '</td>';
                html += '<td class="mastermind-quiz-match-arrow">&rarr;</td>';
                html += '<td class="mastermind-quiz-match-answer">' + escapeHtml(sq.answer) + '</td>';
                html += '</tr>';
            }
            html += '</table>';

        } else if (question.type === 'gapselect' && question.choices) {
            // Show the text with blanks highlighted.
            var gapText = questionText.replace(/\[\[(\d+)\]\]/g, '<span class="mastermind-quiz-gap-blank">____</span>');
            html += '<p class="mastermind-quiz-gap-text">' + gapText + '</p>';
            html += '<div class="mastermind-quiz-gap-choices">';
            html += '<span class="mastermind-quiz-sa-label">Choices: </span>';
            var choiceTexts = question.choices.map(function(ch) {
                return escapeHtml(ch.answer) + ' (group ' + ch.choicegroup + ')';
            });
            html += choiceTexts.join(', ');
            html += '</div>';

        } else if (question.type === 'numerical' && question.answers) {
            html += '<div class="mastermind-quiz-numerical-answer">';
            for (var n = 0; n < question.answers.length; n++) {
                var numAns = question.answers[n];
                if (numAns.fraction > 0) {
                    html += '<span class="mastermind-quiz-sa-label">Answer: </span>';
                    html += '<span class="mastermind-quiz-answer-correct">' + escapeHtml(numAns.answer);
                    if (numAns.tolerance) {
                        html += ' &plusmn; ' + escapeHtml(numAns.tolerance);
                    }
                    html += '</span>';
                    break;
                }
            }
            html += '</div>';
        }

        html += '</div></div>';
        return html;
    }

    /**
     *
     * @param {Array} questions
     */
    function showQuizPreviewModal(questions) {
        var modal = document.getElementById('quiz-preview-modal');
        if (!modal) {
            return;
        }
        moveToBody(modal);

        var listEl = document.getElementById('quiz-preview-questions-list');
        if (listEl) {
            var html = '';
            for (var i = 0; i < questions.length; i++) {
                html += renderQuestionCard(questions[i], i);
            }
            listEl.innerHTML = html;

            // Bind checkbox change events.
            var checkboxes = listEl.querySelectorAll('.mastermind-quiz-checkbox');
            for (var c = 0; c < checkboxes.length; c++) {
                checkboxes[c].addEventListener('change', function() {
                    var card = this.closest('.mastermind-quiz-question-card');
                    if (card) {
                        if (this.checked) {
                            card.classList.add('selected');
                        } else {
                            card.classList.remove('selected');
                        }
                    }
                    updateSelectedCount();
                });
            }
        }

        updateSelectedCount();
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closeQuizPreviewModal() {
        var modal = document.getElementById('quiz-preview-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    /**
     *
     */
    function updateSelectedCount() {
        var checkboxes = document.querySelectorAll('#quiz-preview-questions-list .mastermind-quiz-checkbox');
        var count = 0;
        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                count++;
            }
        }
        var countEl = document.getElementById('quiz-selected-count');
        if (countEl) {
            countEl.textContent = count + ' selected';
        }
    }

    /**
     *
     */
    function addSelectedQuestions() {
        if (!lastQuizResponse || !lastQuizResponse.length) {
            return;
        }

        var checkboxes = document.querySelectorAll('#quiz-preview-questions-list .mastermind-quiz-checkbox');
        var selected = [];
        for (var i = 0; i < checkboxes.length; i++) {
            if (checkboxes[i].checked) {
                var idx = parseInt(checkboxes[i].getAttribute('data-index'), 10);
                if (lastQuizResponse[idx]) {
                    selected.push(lastQuizResponse[idx]);
                }
            }
        }

        if (selected.length === 0) {
            Notification.addNotification({
                message: 'Please select at least one question to add.',
                type: 'warning'
            });
            return;
        }

        closeQuizPreviewModal();
        showProgress();

        var btn = document.getElementById('generate-questions-btn');
        var quizid = btn ? btn.getAttribute('data-quizid') : 0;

        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_quiz_questions',
            args: {
                courseid: courseid,
                quizid: parseInt(quizid, 10),
                quizname: '',
                quizdescription: '',
                difficultylevel: '',
                questioncount: 8,
                academiclevel: '',
                sectionname: '',
                courseactivities: '[]',
                previewonly: false,
                selectedquestions: JSON.stringify(selected)
            }
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    showCompletedState('quiz');
                    Notification.addNotification({
                        message: response.questioncount + ' questions added to your quiz! Reloading...',
                        type: 'success'
                    });
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    Notification.addNotification({
                        message: response.message || 'Error adding questions',
                        type: 'error'
                    });
                    resetQuizUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({
                    message: 'Error: ' + (error.message || 'Connection error'),
                    type: 'error'
                });
                resetQuizUI();
            });
    }

    // ─── Forum generation (with preview) ────────────────────────────────

    /**
     *
     */
    function resetForumUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-draft-btn');
        var optionsPanel = document.getElementById('forum-options-panel');
        if (introDiv) {
 introDiv.style.display = 'block';
}
        if (btn) {
 btn.style.display = 'block';
}
        if (optionsPanel) {
 optionsPanel.style.display = 'flex';
}
    }

    /**
     *
     * @param {string} coursename
     * @param {string} forumname
     * @param {string} forumdescription
     */
    function generateForumDraft(coursename, forumname, forumdescription) {
        var btn = document.getElementById('generate-draft-btn');
        if (!btn) {
 return;
}
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
 introDiv.style.display = 'none';
}
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('forum-options-panel');
        if (optionsPanel) {
 optionsPanel.style.display = 'none';
}
        showProgress();

        var typeSelect = document.getElementById('forum-type-select');
        var levelSelect = document.getElementById('forum-level-select');
        var countSelect = document.getElementById('forum-count-select');
        var sectionSelect = document.getElementById('id_section');

        logFeedback('generate', 'forum');
        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_forum_content',
            args: {
                courseid: courseid,
                coursename: coursename,
                forumname: forumname,
                forumdescription: forumdescription,
                forumtype: typeSelect ? typeSelect.value : '',
                academiclevel: levelSelect ? levelSelect.value : '',
                discussioncount: countSelect ? parseInt(countSelect.value, 10) : 5,
                sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
                courseactivities: '[]'
            }
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    lastForumResponse = response;
                    showForumPreviewModal(response);
                } else {
                    Notification.addNotification({message: response.message || 'Error generating forum content', type: 'error'});
                    resetForumUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({message: 'Error: ' + (error.message || 'Connection error'), type: 'error'});
                resetForumUI();
            });
    }

    /**
     *
     * @param {Object} response
     */
    function showForumPreviewModal(response) {
        var modal = document.getElementById('forum-preview-modal');
        if (!modal) {
 return;
}
        moveToBody(modal);

        var introEl = document.getElementById('forum-preview-introduction');
        if (introEl) {
 introEl.innerHTML = response.introduction || '';
}

        var discussionsEl = document.getElementById('forum-preview-discussions');
        if (discussionsEl) {
            var discussions = parseJSON(response.discussions);
            if (discussions.length) {
                var html = '';
                for (var i = 0; i < discussions.length; i++) {
                    var d = discussions[i];
                    html += '<div class="mastermind-preview-card">';
                    html += '<h5>' + escapeHtml(d.title || 'Discussion ' + (i + 1)) + '</h5>';
                    html += '<p>' + escapeHtml(d.message || '') + '</p>';
                    html += '</div>';
                }
                discussionsEl.innerHTML = html;
            } else {
                discussionsEl.innerHTML = '<p>No discussions generated.</p>';
            }
        }

        var guidelinesSection = document.getElementById('forum-preview-guidelines-section');
        var guidelinesList = document.getElementById('forum-preview-guidelines-list');
        if (guidelinesSection && guidelinesList) {
            var guidelines = parseJSON(response.participation_guidelines);
            if (guidelines.length) {
                guidelinesList.innerHTML = guidelines.map(function(g) {
 return '<li>' + escapeHtml(g) + '</li>';
}).join('');
                guidelinesSection.style.display = 'block';
            } else {
                guidelinesSection.style.display = 'none';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closeForumPreviewModal() {
        var modal = document.getElementById('forum-preview-modal');
        if (modal) {
 modal.style.display = 'none';
}
        document.body.style.overflow = '';
    }

    /**
     *
     */
    function applyForumContent() {
        if (!lastForumResponse) {
            return;
        }
        var btn = document.getElementById('forum-preview-apply-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Adding discussions...';
        }
        var generateBtn = document.getElementById('generate-draft-btn');
        var cmid = generateBtn ? (generateBtn.getAttribute('data-cmid') || 0) : 0;

        Ajax.call([{
            methodname: 'block_mastermind_assistant_apply_forum_discussions',
            args: {
                courseid: courseid,
                cmid: parseInt(cmid, 10),
                discussions: lastForumResponse.discussions || '[]'
            }
        }])[0].done(function(result) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Discussions';
            }
            if (result.success) {
                closeForumPreviewModal();
                showCompletedState('forum');
                Notification.addNotification({
                    message: result.message, type: 'success'
                });
                // Reload page to show new discussions.
                setTimeout(function() {
 window.location.reload();
}, 1500);
            } else {
                Notification.addNotification({
                    message: result.message, type: 'error'
                });
            }
        }).fail(function(err) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Discussions';
            }
            Notification.addNotification({
                message: 'Error: ' + (err.message || err), type: 'error'
            });
        });
    }

    // ─── Lesson generation (with preview) ────────────────────────────────

    /**
     *
     */
    function resetLessonUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-draft-btn');
        var optionsPanel = document.getElementById('lesson-options-panel');
        if (introDiv) {
 introDiv.style.display = 'block';
}
        if (btn) {
 btn.style.display = 'block';
}
        if (optionsPanel) {
 optionsPanel.style.display = 'flex';
}
    }

    /**
     *
     * @param {string} coursename
     * @param {string} lessonname
     * @param {string} lessondescription
     */
    function generateLessonDraft(coursename, lessonname, lessondescription) {
        var btn = document.getElementById('generate-draft-btn');
        if (!btn) {
 return;
}
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
 introDiv.style.display = 'none';
}
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('lesson-options-panel');
        if (optionsPanel) {
 optionsPanel.style.display = 'none';
}
        showProgress();

        var levelSelect = document.getElementById('lesson-level-select');
        var countSelect = document.getElementById('lesson-count-select');
        var sectionSelect = document.getElementById('id_section');

        logFeedback('generate', 'lesson');
        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_lesson_content',
            args: {
                courseid: courseid,
                coursename: coursename,
                lessonname: lessonname,
                lessondescription: lessondescription,
                academiclevel: levelSelect ? levelSelect.value : '',
                pagecount: countSelect ? parseInt(countSelect.value, 10) : 6,
                sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
                courseactivities: '[]'
            }
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    lastLessonResponse = response;
                    showLessonPreviewModal(response);
                } else {
                    Notification.addNotification({message: response.message || 'Error generating lesson content', type: 'error'});
                    resetLessonUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({message: 'Error: ' + (error.message || 'Connection error'), type: 'error'});
                resetLessonUI();
            });
    }

    /**
     *
     * @param {Object} response
     */
    // eslint-disable-next-line complexity
    function showLessonPreviewModal(response) {
        var modal = document.getElementById('lesson-preview-modal');
        if (!modal) {
 return;
}
        moveToBody(modal);

        var pagesEl = document.getElementById('lesson-preview-pages');
        if (pagesEl) {
            var pages = parseJSON(response.pages);
            if (pages.length) {
                var html = '';
                for (var i = 0; i < pages.length; i++) {
                    var p = pages[i];
                    var typeLabel = ' (Content)';
                    if (p.page_type === 'question') {
                        var qt = (p.question_type || 'multichoice').charAt(0).toUpperCase() +
                            (p.question_type || 'multichoice').slice(1);
                        typeLabel = ' (' + qt + ')';
                    }
                    html += '<div class="mastermind-preview-card">';
                    html += '<h5>' + escapeHtml(p.title || 'Page ' + (i + 1)) +
                        ' <span style="color:#666;font-size:0.85em;">' +
                        typeLabel + '</span></h5>';
                    html += '<div>' + (p.content || '') + '</div>';
                    // Show answers summary for question pages.
                    if (p.page_type === 'question' && p.answers && p.answers.length) {
                        html += '<div style="margin-top:8px;padding:10px 12px;background:#f1f5f9;' +
                            'border-radius:6px;font-size:0.9em;color:#334155;border:1px solid #e2e8f0;">';
                        // eslint-disable-next-line max-depth
                        if (p.question_type === 'matching') {
                            html += '<strong style="color:#0f172a;">Matching pairs:</strong>' +
                                '<ul style="margin:4px 0;color:#475569;">';
                            // eslint-disable-next-line max-depth
                            for (var j = 0; j < p.answers.length; j++) {
                                html += '<li>' + escapeHtml(p.answers[j].text || '') +
                                    ' &harr; ' + escapeHtml(p.answers[j].match || '') + '</li>';
                            }
                            html += '</ul>';
                        } else if (p.question_type === 'essay') {
                            html += '<em style="color:#64748b;">Open-ended essay response</em>';
                        } else {
                            html += '<strong style="color:#0f172a;">Answers:</strong>' +
                                '<ul style="margin:4px 0;color:#475569;">';
                            // eslint-disable-next-line max-depth
                            for (var k = 0; k < p.answers.length; k++) {
                                var a = p.answers[k];
                                var marker = a.score ? ' \u2713' : '';
                                html += '<li>' + escapeHtml(a.text || '') +
                                    (marker ? '<span style="color:#15803d;font-weight:bold;">' +
                                    marker + '</span>' : '') + '</li>';
                            }
                            html += '</ul>';
                        }
                        html += '</div>';
                    }
                    html += '</div>';
                }
                pagesEl.innerHTML = html;
            } else {
                pagesEl.innerHTML = '<p>No pages generated.</p>';
            }
        }

        var objSection = document.getElementById('lesson-preview-objectives-section');
        var objList = document.getElementById('lesson-preview-objectives-list');
        if (objSection && objList) {
            var objectives = parseJSON(response.learning_objectives);
            if (objectives.length) {
                objList.innerHTML = objectives.map(function(o) {
 return '<li>' + escapeHtml(o) + '</li>';
}).join('');
                objSection.style.display = 'block';
            } else {
                objSection.style.display = 'none';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closeLessonPreviewModal() {
        var modal = document.getElementById('lesson-preview-modal');
        if (modal) {
 modal.style.display = 'none';
}
        document.body.style.overflow = '';
    }

    /**
     *
     */
    function applyLessonContent() {
        if (!lastLessonResponse) {
            return;
        }
        var btn = document.getElementById('lesson-preview-apply-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Adding pages...';
        }
        var generateBtn = document.getElementById('generate-draft-btn');
        var cmid = generateBtn ? (generateBtn.getAttribute('data-cmid') || 0) : 0;

        Ajax.call([{
            methodname: 'block_mastermind_assistant_apply_lesson_pages',
            args: {
                courseid: courseid,
                cmid: parseInt(cmid, 10),
                pages: lastLessonResponse.pages || '[]'
            }
        }])[0].done(function(result) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Lesson Pages';
            }
            if (result.success) {
                closeLessonPreviewModal();
                showCompletedState('lesson');
                Notification.addNotification({
                    message: result.message, type: 'success'
                });
                setTimeout(function() {
 window.location.reload();
}, 1500);
            } else {
                Notification.addNotification({
                    message: result.message, type: 'error'
                });
            }
        }).fail(function(err) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Lesson Pages';
            }
            Notification.addNotification({
                message: 'Error: ' + (err.message || err), type: 'error'
            });
        });
    }

    // ─── Glossary generation (with preview) ──────────────────────────────

    /**
     *
     */
    function resetGlossaryUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-draft-btn');
        var optionsPanel = document.getElementById('glossary-options-panel');
        if (introDiv) {
 introDiv.style.display = 'block';
}
        if (btn) {
 btn.style.display = 'block';
}
        if (optionsPanel) {
 optionsPanel.style.display = 'flex';
}
    }

    /**
     *
     * @param {string} coursename
     * @param {string} glossaryname
     * @param {string} glossarydescription
     */
    function generateGlossaryDraft(coursename, glossaryname, glossarydescription) {
        var btn = document.getElementById('generate-draft-btn');
        if (!btn) {
 return;
}
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
 introDiv.style.display = 'none';
}
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('glossary-options-panel');
        if (optionsPanel) {
 optionsPanel.style.display = 'none';
}
        showProgress();

        var levelSelect = document.getElementById('glossary-level-select');
        var countSelect = document.getElementById('glossary-count-select');
        var sectionSelect = document.getElementById('id_section');

        logFeedback('generate', 'glossary');
        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_glossary_entries',
            args: {
                courseid: courseid,
                coursename: coursename,
                glossaryname: glossaryname,
                glossarydescription: glossarydescription,
                academiclevel: levelSelect ? levelSelect.value : '',
                entrycount: countSelect ? parseInt(countSelect.value, 10) : 10,
                sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
                courseactivities: '[]'
            }
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    lastGlossaryResponse = response;
                    showGlossaryPreviewModal(response);
                } else {
                    Notification.addNotification({message: response.message || 'Error generating glossary entries', type: 'error'});
                    resetGlossaryUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({message: 'Error: ' + (error.message || 'Connection error'), type: 'error'});
                resetGlossaryUI();
            });
    }

    /**
     *
     * @param {Object} response
     */
    function showGlossaryPreviewModal(response) {
        var modal = document.getElementById('glossary-preview-modal');
        if (!modal) {
 return;
}
        moveToBody(modal);

        var descEl = document.getElementById('glossary-preview-description');
        if (descEl) {
 descEl.innerHTML = response.description || '';
}

        var entriesEl = document.getElementById('glossary-preview-entries');
        if (entriesEl) {
            var entries = parseJSON(response.entries);
            if (entries.length) {
                var html = '<dl class="mastermind-glossary-list">';
                for (var i = 0; i < entries.length; i++) {
                    html += '<dt><strong>' + escapeHtml(entries[i].concept || '') + '</strong></dt>';
                    html += '<dd>' + escapeHtml(entries[i].definition || '') + '</dd>';
                }
                html += '</dl>';
                entriesEl.innerHTML = html;
            } else {
                entriesEl.innerHTML = '<p>No entries generated.</p>';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closeGlossaryPreviewModal() {
        var modal = document.getElementById('glossary-preview-modal');
        if (modal) {
 modal.style.display = 'none';
}
        document.body.style.overflow = '';
    }

    /**
     *
     */
    function applyGlossaryContent() {
        if (!lastGlossaryResponse) {
            return;
        }
        var btn = document.getElementById('glossary-preview-apply-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Adding entries...';
        }
        var generateBtn = document.getElementById('generate-draft-btn');
        var cmid = generateBtn ? (generateBtn.getAttribute('data-cmid') || 0) : 0;

        Ajax.call([{
            methodname: 'block_mastermind_assistant_apply_glossary_entries',
            args: {
                courseid: courseid,
                cmid: parseInt(cmid, 10),
                entries: lastGlossaryResponse.entries || '[]'
            }
        }])[0].done(function(result) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Glossary Entries';
            }
            if (result.success) {
                closeGlossaryPreviewModal();
                showCompletedState('glossary');
                Notification.addNotification({
                    message: result.message, type: 'success'
                });
                setTimeout(function() {
 window.location.reload();
}, 1500);
            } else {
                Notification.addNotification({
                    message: result.message, type: 'error'
                });
            }
        }).fail(function(err) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Glossary Entries';
            }
            Notification.addNotification({
                message: 'Error: ' + (err.message || err), type: 'error'
            });
        });
    }

    // ─── Book generation (with preview) ──────────────────────────────────

    /**
     *
     */
    function resetBookUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-draft-btn');
        var optionsPanel = document.getElementById('book-options-panel');
        if (introDiv) {
 introDiv.style.display = 'block';
}
        if (btn) {
 btn.style.display = 'block';
}
        if (optionsPanel) {
 optionsPanel.style.display = 'flex';
}
    }

    /**
     *
     * @param {string} coursename
     * @param {string} bookname
     * @param {string} bookdescription
     */
    function generateBookDraft(coursename, bookname, bookdescription) {
        var btn = document.getElementById('generate-draft-btn');
        if (!btn) {
 return;
}
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
 introDiv.style.display = 'none';
}
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('book-options-panel');
        if (optionsPanel) {
 optionsPanel.style.display = 'none';
}
        showProgress();

        var levelSelect = document.getElementById('book-level-select');
        var countSelect = document.getElementById('book-count-select');
        var lengthSelect = document.getElementById('book-length-select');
        var sectionSelect = document.getElementById('id_section');

        logFeedback('generate', 'book');
        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_book_content',
            args: {
                courseid: courseid,
                coursename: coursename,
                bookname: bookname,
                bookdescription: bookdescription,
                academiclevel: levelSelect ? levelSelect.value : '',
                chaptercount: countSelect ? parseInt(countSelect.value, 10) : 5,
                targetlength: lengthSelect ? lengthSelect.value : '',
                sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
                courseactivities: '[]'
            }
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    lastBookResponse = response;
                    showBookPreviewModal(response);
                } else {
                    Notification.addNotification({message: response.message || 'Error generating book content', type: 'error'});
                    resetBookUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({message: 'Error: ' + (error.message || 'Connection error'), type: 'error'});
                resetBookUI();
            });
    }

    /**
     *
     * @param {Object} response
     */
    function showBookPreviewModal(response) {
        var modal = document.getElementById('book-preview-modal');
        if (!modal) {
 return;
}
        moveToBody(modal);

        var chaptersEl = document.getElementById('book-preview-chapters');
        if (chaptersEl) {
            var chapters = parseJSON(response.chapters);
            if (chapters.length) {
                var html = '';
                for (var i = 0; i < chapters.length; i++) {
                    var ch = chapters[i];
                    html += '<div class="mastermind-preview-card">';
                    html += '<h5>' + escapeHtml(ch.title || 'Chapter ' + (i + 1)) + '</h5>';
                    html += '<div>' + (ch.content || '') + '</div>';
                    if (ch.subchapters && ch.subchapters.length) {
                        // eslint-disable-next-line max-depth
                        for (var s = 0; s < ch.subchapters.length; s++) {
                            var sub = ch.subchapters[s];
                            html += '<div style="margin-left:1rem;border-left:2px solid #e0e0e0;' +
                                'padding-left:1rem;margin-top:0.5rem;">';
                            html += '<h6>' + escapeHtml(sub.title || 'Subchapter') + '</h6>';
                            html += '<div>' + (sub.content || '') + '</div>';
                            html += '</div>';
                        }
                    }
                    html += '</div>';
                }
                chaptersEl.innerHTML = html;
            } else {
                chaptersEl.innerHTML = '<p>No chapters generated.</p>';
            }
        }

        var objSection = document.getElementById('book-preview-objectives-section');
        var objList = document.getElementById('book-preview-objectives-list');
        if (objSection && objList) {
            var objectives = parseJSON(response.learning_objectives);
            if (objectives.length) {
                objList.innerHTML = objectives.map(function(o) {
 return '<li>' + escapeHtml(o) + '</li>';
}).join('');
                objSection.style.display = 'block';
            } else {
                objSection.style.display = 'none';
            }
        }

        var summarySection = document.getElementById('book-preview-summary-section');
        var summaryText = document.getElementById('book-preview-summary-text');
        if (summarySection && summaryText) {
            if (response.content_summary) {
                summaryText.textContent = response.content_summary;
                summarySection.style.display = 'block';
            } else {
                summarySection.style.display = 'none';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closeBookPreviewModal() {
        var modal = document.getElementById('book-preview-modal');
        if (modal) {
 modal.style.display = 'none';
}
        document.body.style.overflow = '';
    }

    /**
     *
     */
    function applyBookContent() {
        if (!lastBookResponse) {
            return;
        }
        var btn = document.getElementById('book-preview-apply-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Adding chapters...';
        }
        var generateBtn = document.getElementById('generate-draft-btn');
        var cmid = generateBtn ? (generateBtn.getAttribute('data-cmid') || 0) : 0;

        Ajax.call([{
            methodname: 'block_mastermind_assistant_apply_book_chapters',
            args: {
                courseid: courseid,
                cmid: parseInt(cmid, 10),
                chapters: lastBookResponse.chapters || '[]'
            }
        }])[0].done(function(result) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Book Chapters';
            }
            if (result.success) {
                closeBookPreviewModal();
                showCompletedState('book');
                Notification.addNotification({
                    message: result.message, type: 'success'
                });
                setTimeout(function() {
 window.location.reload();
}, 1500);
            } else {
                Notification.addNotification({
                    message: result.message, type: 'error'
                });
            }
        }).fail(function(err) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Add Book Chapters';
            }
            Notification.addNotification({
                message: 'Error: ' + (err.message || err), type: 'error'
            });
        });
    }

    // ─── URL generation (with preview) ───────────────────────────────────

    /**
     *
     */
    function resetUrlUI() {
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        var btn = document.getElementById('generate-draft-btn');
        var optionsPanel = document.getElementById('url-options-panel');
        if (introDiv) {
 introDiv.style.display = 'block';
}
        if (btn) {
 btn.style.display = 'block';
}
        if (optionsPanel) {
 optionsPanel.style.display = 'flex';
}
    }

    /**
     *
     * @param {string} coursename
     * @param {string} urlname
     * @param {string} urldescription
     */
    function generateUrlDraft(coursename, urlname, urldescription) {
        var btn = document.getElementById('generate-draft-btn');
        if (!btn) {
 return;
}
        var introDiv = document.querySelector('.mastermind-mod-draft-intro');
        if (introDiv) {
 introDiv.style.display = 'none';
}
        btn.style.display = 'none';
        var optionsPanel = document.getElementById('url-options-panel');
        if (optionsPanel) {
 optionsPanel.style.display = 'none';
}
        showProgress();

        var levelSelect = document.getElementById('url-level-select');
        var countSelect = document.getElementById('url-count-select');
        var sectionSelect = document.getElementById('id_section');

        logFeedback('generate', 'url');
        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_url_resource',
            args: {
                courseid: courseid,
                coursename: coursename,
                urlname: urlname,
                urldescription: urldescription,
                academiclevel: levelSelect ? levelSelect.value : '',
                resourcecount: countSelect ? parseInt(countSelect.value, 10) : 5,
                sectionname: sectionSelect ? sectionSelect.options[sectionSelect.selectedIndex].text : '',
                courseactivities: '[]'
            }
        }])[0]
            .then(function(response) {
                hideProgress();
                if (response.success) {
                    lastUrlResponse = response;
                    showUrlPreviewModal(response);
                } else {
                    Notification.addNotification({
                        message: response.message || 'Error generating URL recommendations',
                        type: 'error'
                    });
                    resetUrlUI();
                }
                return response;
            })
            .catch(function(error) {
                hideProgress();
                Notification.addNotification({message: 'Error: ' + (error.message || 'Connection error'), type: 'error'});
                resetUrlUI();
            });
    }

    /**
     *
     * @param {Object} response
     */
    function showUrlPreviewModal(response) {
        var modal = document.getElementById('url-preview-modal');
        if (!modal) {
 return;
}
        moveToBody(modal);

        var summarySection = document.getElementById('url-preview-summary-top');
        var summaryText = document.getElementById('url-preview-summary-text');
        if (summarySection && summaryText) {
            if (response.topic_summary) {
                summaryText.textContent = response.topic_summary;
                summarySection.style.display = 'block';
            } else {
                summarySection.style.display = 'none';
            }
        }

        var linksEl = document.getElementById('url-preview-links');
        if (linksEl) {
            var urls = parseJSON(response.urls);
            if (urls.length) {
                var html = '';
                for (var i = 0; i < urls.length; i++) {
                    var u = urls[i];
                    var typeLabel = u.resource_type
                        ? ' <span style="color:#666;font-size:0.85em;">(' +
                            escapeHtml(u.resource_type) + ')</span>'
                        : '';
                    html += '<div class="mastermind-preview-card ' +
                        'mastermind-url-card" data-url="' +
                        escapeHtml(u.url || '') + '" data-title="' +
                        escapeHtml(u.title || '') +
                        '" style="cursor:pointer;">';
                    html += '<div style="display:flex;' +
                        'align-items:center;gap:0.5rem;">';
                    html += '<input type="radio" name="url-selection"' +
                        ' value="' + i + '"' +
                        (i === 0 ? ' checked' : '') + ' />';
                    html += '<h5 style="margin:0;">' +
                        escapeHtml(u.title || 'Resource ' + (i + 1)) +
                        typeLabel + '</h5>';
                    html += '</div>';
                    html += '<p style="margin:0.25rem 0 0 1.5rem;">' +
                        escapeHtml(u.description || '') + '</p>';
                    html += '<p style="margin:0.25rem 0 0 1.5rem;' +
                        'font-size:0.85em;color:#0066cc;">' +
                        escapeHtml(u.url || '') + '</p>';
                    html += '</div>';
                }
                linksEl.innerHTML = html;

                // Clicking a card selects its radio
                var cards = linksEl.querySelectorAll('.mastermind-url-card');
                for (var c = 0; c < cards.length; c++) {
                    cards[c].addEventListener('click', function() {
                        var radio = this.querySelector('input[type="radio"]');
                        if (radio) {
 radio.checked = true;
}
                    });
                }
            } else {
                linksEl.innerHTML = '<p>No URL recommendations generated.</p>';
            }
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /**
     *
     */
    function closeUrlPreviewModal() {
        var modal = document.getElementById('url-preview-modal');
        if (modal) {
 modal.style.display = 'none';
}
        document.body.style.overflow = '';
    }

    /**
     *
     */
    function applyUrlContent() {
        if (!lastUrlResponse) {
 return;
}
        var urls = parseJSON(lastUrlResponse.urls);
        var selectedRadio = document.querySelector('input[name="url-selection"]:checked');
        var selectedIdx = selectedRadio ? parseInt(selectedRadio.value, 10) : 0;
        var selectedUrl = urls[selectedIdx];

        if (!selectedUrl) {
            Notification.addNotification({message: 'Please select a URL to apply.', type: 'warning'});
            return;
        }

        // Try to set the URL field in the form
        var urlField = document.getElementById('id_externalurl');
        if (urlField) {
            urlField.value = selectedUrl.url || '';
            urlField.dispatchEvent(new Event('change', {bubbles: true}));
        }

        // Also try to set the name field
        var nameField = document.getElementById('id_name');
        if (nameField && !nameField.value.trim()) {
            nameField.value = selectedUrl.title || '';
            nameField.dispatchEvent(new Event('change', {bubbles: true}));
        }

        // Set description in editor if available
        if (selectedUrl.description) {
            applyContentToEditor('<p>' + selectedUrl.description + '</p>', 'id_introeditor');
        }

        markFormChanged();

        closeUrlPreviewModal();
        showCompletedState('url');
        Notification.addNotification({message: 'URL applied successfully!', type: 'success'});
    }

    // ─── Shared modal button binder ─────────────────────────────────────

    /**
     *
     * @param {string} prefix
     * @param {Function} closeFn
     * @param {Function} resetFn
     * @param {Function} applyFn
     * @param {Function} regenFn
     */
    function bindModalButtons(prefix, closeFn, resetFn, applyFn, regenFn) {
        var applyBtn = document.getElementById(prefix + '-preview-apply-btn');
        if (applyBtn) {
            applyBtn.addEventListener('click', function() {
                logFeedback('apply', prefix);
                applyFn();
            });
        }

        var cancelBtn = document.getElementById(prefix + '-preview-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                logFeedback('discard', prefix);
                closeFn(); resetFn();
            });
        }

        var closeBtn = document.getElementById(prefix + '-preview-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                logFeedback('discard', prefix);
                closeFn(); resetFn();
            });
        }

        var regenBtn = document.getElementById(prefix + '-preview-regenerate-btn');
        if (regenBtn) {
            regenBtn.addEventListener('click', function() {
                logFeedback('regenerate', prefix);
                closeFn(); regenFn();
            });
        }

        var modal = document.getElementById(prefix + '-preview-modal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    logFeedback('discard', prefix);
                    closeFn(); resetFn();
                }
            });
        }
    }

    // ─── Initialization ──────────────────────────────────────────────────

    /**
     *
     * @param {number} cid
     */
    // eslint-disable-next-line complexity
    function init(cid) {
        courseid = cid;

        // Page + Assignment generate button (shared id).
        var generateBtn = document.getElementById('generate-draft-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', function() {
                var coursename = this.getAttribute('data-coursename');
                var modname = this.getAttribute('data-modname');
                var formValues = readFormValues(this);

                AiPolicy.checkAndProceed(function() {
                    var mn = modname ? modname.toLowerCase() : '';
                    if (mn === 'assign') {
                        generateAssignmentDraft(coursename, formValues.name, formValues.description);
                    } else if (mn === 'forum') {
                        generateForumDraft(coursename, formValues.name, formValues.description);
                    } else if (mn === 'lesson') {
                        generateLessonDraft(coursename, formValues.name, formValues.description);
                    } else if (mn === 'glossary') {
                        generateGlossaryDraft(coursename, formValues.name, formValues.description);
                    } else if (mn === 'book') {
                        generateBookDraft(coursename, formValues.name, formValues.description);
                    } else if (mn === 'url') {
                        generateUrlDraft(coursename, formValues.name, formValues.description);
                    } else {
                        generatePageDraft(coursename, formValues.name, formValues.description);
                    }
                });
            });
        }

        // Quiz button.
        var generateQuestionsBtn = document.getElementById('generate-questions-btn');
        if (generateQuestionsBtn) {
            generateQuestionsBtn.addEventListener('click', function() {
                AiPolicy.checkAndProceed(function() {
                    generateQuizDraft();
                });
            });
        }

        // Quiz source-document dropzone (mirrors the course-creation upload UX).
        var quizSourceInput = document.getElementById('quiz-source-doc');
        var quizSourcePicker = document.getElementById('quiz-source-doc-btn');
        var quizSourceDropzone = document.getElementById('quiz-upload-dropzone');
        var quizSourceSelected = document.getElementById('quiz-source-doc-selected');
        var quizSourceName = document.getElementById('quiz-source-doc-name');
        var quizSourceSize = document.getElementById('quiz-source-doc-size');
        var quizSourceRemove = document.getElementById('quiz-source-doc-remove');

        /**
         * Toggle the quiz source-document UI between dropzone and selected-file states.
         * @param {File|null} file Selected file, or null to clear.
         */
        function setQuizSourceFile(file) {
            if (!file) {
                if (quizSourceInput) {
                    quizSourceInput.value = '';
                }
                if (quizSourceDropzone) {
                    quizSourceDropzone.style.display = '';
                }
                if (quizSourceSelected) {
                    quizSourceSelected.style.display = 'none';
                }
                return;
            }
            if (quizSourceName) {
                quizSourceName.textContent = file.name;
            }
            if (quizSourceSize) {
                var sizeKb = Math.round(file.size / 1024);
                quizSourceSize.textContent = '(' + sizeKb + ' KB)';
            }
            if (quizSourceDropzone) {
                quizSourceDropzone.style.display = 'none';
            }
            if (quizSourceSelected) {
                quizSourceSelected.style.display = '';
            }
        }

        if (quizSourcePicker && quizSourceInput) {
            quizSourcePicker.addEventListener('click', function() {
                quizSourceInput.click();
            });
        }
        if (quizSourceInput) {
            quizSourceInput.addEventListener('change', function() {
                setQuizSourceFile(this.files && this.files[0]);
            });
        }
        if (quizSourceRemove) {
            quizSourceRemove.addEventListener('click', function() {
                setQuizSourceFile(null);
            });
        }
        if (quizSourceDropzone && quizSourceInput) {
            quizSourceDropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('mastermind-dropzone-active');
            });
            quizSourceDropzone.addEventListener('dragleave', function() {
                this.classList.remove('mastermind-dropzone-active');
            });
            quizSourceDropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('mastermind-dropzone-active');
                var dropped = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (dropped) {
                    var dt = new DataTransfer();
                    dt.items.add(dropped);
                    quizSourceInput.files = dt.files;
                    setQuizSourceFile(dropped);
                }
            });
        }

        // ─── Assignment preview modal buttons ────────────────────────────
        var applyBtn = document.getElementById('preview-apply-btn');
        if (applyBtn) {
            applyBtn.addEventListener('click', function() {
                logFeedback('apply', 'assign');
                applyAssignmentContent();
            });
        }

        var cancelBtn = document.getElementById('preview-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                logFeedback('discard', 'assign');
                closeAssignPreviewModal();
                resetAssignmentUI();
            });
        }

        var closeBtn = document.getElementById('preview-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                logFeedback('discard', 'assign');
                closeAssignPreviewModal();
                resetAssignmentUI();
            });
        }

        var regenBtn = document.getElementById('preview-regenerate-btn');
        if (regenBtn) {
            regenBtn.addEventListener('click', function() {
                logFeedback('regenerate', 'assign');
                closeAssignPreviewModal();
                var btn = document.getElementById('generate-draft-btn');
                if (btn) {
                    var coursename = btn.getAttribute('data-coursename');
                    var fv = readFormValues(btn);
                    generateAssignmentDraft(coursename, fv.name, fv.description);
                }
            });
        }

        var assignModal = document.getElementById('assign-preview-modal');
        if (assignModal) {
            assignModal.addEventListener('click', function(e) {
                if (e.target === assignModal) {
                    logFeedback('discard', 'assign');
                    closeAssignPreviewModal();
                    resetAssignmentUI();
                }
            });
        }

        // ─── Page preview modal buttons ──────────────────────────────────
        var pageApplyBtn = document.getElementById('page-preview-apply-btn');
        if (pageApplyBtn) {
            pageApplyBtn.addEventListener('click', function() {
                logFeedback('apply', 'page');
                applyPageContent();
            });
        }

        var pageCancelBtn = document.getElementById('page-preview-cancel-btn');
        if (pageCancelBtn) {
            pageCancelBtn.addEventListener('click', function() {
                logFeedback('discard', 'page');
                closePagePreviewModal();
                resetPageUI();
            });
        }

        var pageCloseBtn = document.getElementById('page-preview-close-btn');
        if (pageCloseBtn) {
            pageCloseBtn.addEventListener('click', function() {
                logFeedback('discard', 'page');
                closePagePreviewModal();
                resetPageUI();
            });
        }

        var pageRegenBtn = document.getElementById('page-preview-regenerate-btn');
        if (pageRegenBtn) {
            pageRegenBtn.addEventListener('click', function() {
                logFeedback('regenerate', 'page');
                closePagePreviewModal();
                var btn = document.getElementById('generate-draft-btn');
                if (btn) {
                    var coursename = btn.getAttribute('data-coursename');
                    var fv = readFormValues(btn);
                    generatePageDraft(coursename, fv.name, fv.description);
                }
            });
        }

        var pageModal = document.getElementById('page-preview-modal');
        if (pageModal) {
            pageModal.addEventListener('click', function(e) {
                if (e.target === pageModal) {
                    logFeedback('discard', 'page');
                    closePagePreviewModal();
                    resetPageUI();
                }
            });
        }

        // ─── Quiz preview modal buttons ──────────────────────────────────
        var quizAddBtn = document.getElementById('quiz-preview-add-btn');
        if (quizAddBtn) {
            quizAddBtn.addEventListener('click', function() {
                logFeedback('apply', 'quiz');
                addSelectedQuestions();
            });
        }

        var quizCancelBtn = document.getElementById('quiz-preview-cancel-btn');
        if (quizCancelBtn) {
            quizCancelBtn.addEventListener('click', function() {
                logFeedback('discard', 'quiz');
                closeQuizPreviewModal();
                resetQuizUI();
            });
        }

        var quizCloseBtn = document.getElementById('quiz-preview-close-btn');
        if (quizCloseBtn) {
            quizCloseBtn.addEventListener('click', function() {
                logFeedback('discard', 'quiz');
                closeQuizPreviewModal();
                resetQuizUI();
            });
        }

        var quizRegenBtn = document.getElementById('quiz-preview-regenerate-btn');
        if (quizRegenBtn) {
            quizRegenBtn.addEventListener('click', function() {
                logFeedback('regenerate', 'quiz');
                closeQuizPreviewModal();
                generateQuizDraft();
            });
        }

        var quizModal = document.getElementById('quiz-preview-modal');
        if (quizModal) {
            quizModal.addEventListener('click', function(e) {
                if (e.target === quizModal) {
                    logFeedback('discard', 'quiz');
                    closeQuizPreviewModal();
                    resetQuizUI();
                }
            });
        }

        var selectAllBtn = document.getElementById('quiz-select-all-btn');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                var checkboxes = document.querySelectorAll('#quiz-preview-questions-list .mastermind-quiz-checkbox');
                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = true;
                    var card = checkboxes[i].closest('.mastermind-quiz-question-card');
                    if (card) {
                        card.classList.add('selected');
                    }
                }
                updateSelectedCount();
            });
        }

        var deselectAllBtn = document.getElementById('quiz-deselect-all-btn');
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                var checkboxes = document.querySelectorAll('#quiz-preview-questions-list .mastermind-quiz-checkbox');
                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = false;
                    var card = checkboxes[i].closest('.mastermind-quiz-question-card');
                    if (card) {
                        card.classList.remove('selected');
                    }
                }
                updateSelectedCount();
            });
        }

        // ─── Forum preview modal buttons ─────────────────────────────────
        bindModalButtons('forum', closeForumPreviewModal, resetForumUI, applyForumContent, function() {
            var b = document.getElementById('generate-draft-btn');
            if (b) {
                var cn = b.getAttribute('data-coursename');
                var fv = readFormValues(b);
                generateForumDraft(cn, fv.name, fv.description);
            }
        });

        // ─── Lesson preview modal buttons ────────────────────────────────
        bindModalButtons('lesson', closeLessonPreviewModal, resetLessonUI, applyLessonContent, function() {
            var b = document.getElementById('generate-draft-btn');
            if (b) {
                var cn = b.getAttribute('data-coursename');
                var fv = readFormValues(b);
                generateLessonDraft(cn, fv.name, fv.description);
            }
        });

        // ─── Glossary preview modal buttons ──────────────────────────────
        bindModalButtons('glossary', closeGlossaryPreviewModal, resetGlossaryUI, applyGlossaryContent, function() {
            var b = document.getElementById('generate-draft-btn');
            if (b) {
                var cn = b.getAttribute('data-coursename');
                var fv = readFormValues(b);
                generateGlossaryDraft(cn, fv.name, fv.description);
            }
        });

        // ─── Book preview modal buttons ──────────────────────────────────
        bindModalButtons('book', closeBookPreviewModal, resetBookUI, applyBookContent, function() {
            var b = document.getElementById('generate-draft-btn');
            if (b) {
                var cn = b.getAttribute('data-coursename');
                var fv = readFormValues(b);
                generateBookDraft(cn, fv.name, fv.description);
            }
        });

        // ─── URL preview modal buttons ───────────────────────────────────
        bindModalButtons('url', closeUrlPreviewModal, resetUrlUI, applyUrlContent, function() {
            var b = document.getElementById('generate-draft-btn');
            if (b) {
                var cn = b.getAttribute('data-coursename');
                var fv = readFormValues(b);
                generateUrlDraft(cn, fv.name, fv.description);
            }
        });
    }

    return {
        init: init
    };
});
