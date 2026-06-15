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
 * AMD module for the AI grading assistant.
 *
 * Shown on assignment grading pages and the quiz manual grading report.
 * Lists items awaiting grading (anonymous labels), analyzes one item per
 * click (1 grading action each) and offers to insert the draft feedback
 * into the page's feedback editor. The grade/mark inputs are never touched.
 *
 * @module     block_mastermind_assistant/grading_assist
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* global tinyMCE */

define(['core/ajax', 'core/notification', 'core/str', 'block_mastermind_assistant/ai_policy'],
function(Ajax, Notification, Str, AiPolicy) {

    var cmid = 0;
    var modtype = '';
    var strings = {};

    /**
     * Escape a string for safe HTML interpolation.
     *
     * @param {string} str Raw string.
     * @returns {string} Escaped HTML.
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * Convert plain feedback text to simple HTML paragraphs.
     *
     * @param {string} text Plain text.
     * @returns {string} HTML markup.
     */
    function textToHtml(text) {
        var paragraphs = String(text).split(/\n{2,}/);
        return paragraphs.map(function(p) {
            return '<p>' + escapeHtml(p).replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    /**
     * Call the grading_assist web service.
     *
     * @param {string} action 'list' or 'analyze'.
     * @param {string} itemid Item identifier (analyze only).
     * @returns {Promise} Resolving to the WS response.
     */
    function callService(action, itemid) {
        return Ajax.call([{
            methodname: 'block_mastermind_assistant_grading_assist',
            args: {
                cmid: cmid,
                modtype: modtype,
                action: action,
                itemid: itemid || ''
            }
        }])[0];
    }

    /**
     * Show a toast-style notification.
     *
     * @param {string} message Message text.
     * @param {string} type Notification type ('success', 'warning', 'error').
     */
    function toast(message, type) {
        Notification.addNotification({message: message, type: type || 'success'});
    }

    /**
     * Show or replace the dismissible error banner inside the grading modal.
     *
     * Moodle toasts render behind the open modal, so route analyze errors into
     * a banner at the top of the modal body instead. Falls back to a toast when
     * the modal isn't open.
     *
     * @param {string} message Error message text.
     */
    function showModalError(message) {
        var modal = document.getElementById('grading-assist-modal');
        var body = modal && modal.querySelector('.mastermind-preview-body');
        if (!modal || modal.style.display === 'none' || !body) {
            toast(message, 'error');
            return;
        }

        clearModalError();

        var banner = document.createElement('div');
        banner.id = 'grading-assist-error';
        banner.className = 'mastermind-modal-error';
        banner.setAttribute('role', 'alert');

        var text = document.createElement('span');
        text.className = 'mastermind-modal-error-text';
        text.textContent = message;
        banner.appendChild(text);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'mastermind-modal-error-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = '&times;';
        close.addEventListener('click', clearModalError);
        banner.appendChild(close);

        body.parentNode.insertBefore(banner, body);
    }

    /**
     * Remove the grading modal's error banner if present.
     */
    function clearModalError() {
        var existing = document.getElementById('grading-assist-error');
        if (existing) {
            existing.remove();
        }
    }

    /**
     * Mark the form as changed so Moodle warns before navigation.
     */
    function markFormChanged() {
        if (typeof M !== 'undefined' && M.core_formchangechecker) {
            M.core_formchangechecker.set_form_changed();
        }
    }

    /**
     * Write content into an Atto contenteditable element and sync its textarea.
     *
     * @param {HTMLElement} editable Atto contenteditable element.
     * @param {string} html HTML content.
     */
    function setAttoContent(editable, html) {
        editable.innerHTML = html;
        editable.dispatchEvent(new Event('input', {bubbles: true}));
        var wrap = editable.closest('.editor_atto_wrap');
        if (wrap) {
            var ta = wrap.querySelector('textarea');
            if (ta) {
                ta.value = html;
            }
        }
        markFormChanged();
        editable.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    /**
     * Insert HTML into a specific editor identified by its textarea id.
     * Tries Atto, then TinyMCE, then the raw textarea.
     *
     * @param {string} textareaId Underlying textarea id.
     * @param {string} html HTML content.
     * @returns {boolean} True when inserted.
     */
    function insertIntoEditorById(textareaId, html) {
        var editable = document.getElementById(textareaId + 'editable');
        if (editable && editable.isContentEditable) {
            setAttoContent(editable, html);
            return true;
        }
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get(textareaId)) {
            var editor = tinyMCE.get(textareaId);
            editor.setContent(html);
            editor.save();
            markFormChanged();
            return true;
        }
        var textarea = document.getElementById(textareaId);
        if (textarea && textarea.tagName === 'TEXTAREA') {
            textarea.value = html;
            textarea.dispatchEvent(new Event('change', {bubbles: true}));
            markFormChanged();
            textarea.scrollIntoView({behavior: 'smooth', block: 'center'});
            return true;
        }
        return false;
    }

    /**
     * Collect the per-attempt comment editor ids on the quiz manual grading page.
     *
     * The behaviour comment textarea is named "q{usage}:{slot}_-comment" with
     * id "...-comment_id". The mark inputs are intentionally never considered.
     *
     * @returns {Array} Textarea ids of comment editors.
     */
    function findQuizCommentEditorIds() {
        var ids = [];
        var textareas = document.querySelectorAll('textarea[name$="-comment"]');
        for (var i = 0; i < textareas.length; i++) {
            if (textareas[i].id) {
                ids.push(textareas[i].id);
            }
        }
        return ids;
    }

    /**
     * Copy text to the clipboard with a legacy fallback.
     *
     * @param {string} text Text to copy.
     * @returns {Promise} Resolving when copied.
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function(resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                resolve();
            } catch (e) {
                reject(e);
            }
            document.body.removeChild(ta);
        });
    }

    /**
     * Insert the draft feedback into the page's feedback field.
     *
     * Assignment grader page: the feedback comments editor. Quiz manual
     * grading page: the per-attempt comment box, but only when exactly one
     * is present (otherwise the right target is ambiguous). In all other
     * cases the text is copied to the clipboard instead. Grade and mark
     * inputs are never touched.
     *
     * @param {string} feedbackText Plain draft feedback text.
     */
    function insertFeedback(feedbackText) {
        var html = textToHtml(feedbackText);
        var inserted = false;

        if (modtype === 'assign') {
            inserted = insertIntoEditorById('id_assignfeedbackcomments_editor', html);
        } else {
            var ids = findQuizCommentEditorIds();
            if (ids.length === 1) {
                inserted = insertIntoEditorById(ids[0], html);
            }
        }

        if (inserted) {
            toast(strings.insertedtoast, 'success');
            return;
        }

        copyToClipboard(feedbackText).then(function() {
            toast(strings.notargettoast, 'warning');
            return null;
        }).catch(function() {
            toast(strings.copyfailed, 'error');
        });
    }

    /**
     * Build the result panel for one analyzed item.
     *
     * @param {Object} analysis Dashboard analysis result.
     * @param {number} maxgrade Maximum grade of the task.
     * @returns {HTMLElement} Result panel element.
     */
    function buildResultPanel(analysis, maxgrade) {
        var panel = document.createElement('div');
        panel.className = 'mastermind-grading-result';

        var html = '';
        if (analysis.summary) {
            html += '<h5>' + escapeHtml(strings.summarylabel) + '</h5>';
            html += '<p>' + escapeHtml(analysis.summary) + '</p>';
        }
        var strengths = analysis.strengths || [];
        if (strengths.length) {
            html += '<h5>' + escapeHtml(strings.strengthslabel) + '</h5><ul>';
            strengths.forEach(function(s) {
                html += '<li>' + escapeHtml(s) + '</li>';
            });
            html += '</ul>';
        }
        var gaps = analysis.gaps || [];
        if (gaps.length) {
            html += '<h5>' + escapeHtml(strings.gapslabel) + '</h5><ul>';
            gaps.forEach(function(g) {
                html += '<li>' + escapeHtml(g) + '</li>';
            });
            html += '</ul>';
        }

        var confidenceKey = 'confidence' + (analysis.confidence || 'medium');
        var confidenceLabel = strings[confidenceKey] || analysis.confidence || '';
        html += '<div class="mastermind-grading-banner alert alert-info">';
        html += '<strong>' + escapeHtml(
            strings.suggestedlabel
                .replace('{$a->grade}', analysis.suggested_grade)
                .replace('{$a->max}', maxgrade)
                .replace('{$a->confidence}', confidenceLabel)
        ) + '</strong>';
        if (analysis.grade_reasoning) {
            html += '<br><small>' + escapeHtml(analysis.grade_reasoning) + '</small>';
        }
        html += '</div>';

        html += '<h5>' + escapeHtml(strings.feedbacklabel) + '</h5>';
        html += '<div class="mastermind-grading-feedback mastermind-preview-content" tabindex="0"></div>';
        html += '<div class="mastermind-grading-actions">';
        html += '<button type="button" class="mastermind-action-button mastermind-grading-insert-btn">' +
            escapeHtml(strings.insertbutton) + '</button> ';
        html += '<button type="button" class="mastermind-secondary-button mastermind-grading-copy-btn">' +
            escapeHtml(strings.copybutton) + '</button>';
        html += '</div>';
        html += '<p class="mastermind-grading-disclaimer"><small>' + escapeHtml(strings.disclaimer) + '</small></p>';

        panel.innerHTML = html;

        var feedbackText = analysis.draft_feedback || '';
        panel.querySelector('.mastermind-grading-feedback').innerHTML = textToHtml(feedbackText);

        panel.querySelector('.mastermind-grading-insert-btn').addEventListener('click', function() {
            insertFeedback(feedbackText);
        });
        panel.querySelector('.mastermind-grading-copy-btn').addEventListener('click', function() {
            copyToClipboard(feedbackText).then(function() {
                toast(strings.copiedtoast, 'success');
                return null;
            }).catch(function() {
                toast(strings.copyfailed, 'error');
            });
        });

        return panel;
    }

    /**
     * Analyze one item and render the result under its row.
     *
     * @param {HTMLElement} row Item row element.
     * @param {string} itemid Item identifier.
     * @param {HTMLButtonElement} button The row's analyze button.
     */
    function analyzeItem(row, itemid, button) {
        button.disabled = true;
        var originalLabel = button.textContent;
        button.textContent = strings.analyzing;

        var oldResult = row.querySelector('.mastermind-grading-result');
        if (oldResult) {
            oldResult.remove();
        }
        clearModalError();

        callService('analyze', itemid).then(function(response) {
            if (!response.success) {
                button.disabled = false;
                button.textContent = originalLabel;
                showModalError(response.error || strings.errorgeneric);
                return null;
            }
            var analysis = {};
            try {
                analysis = JSON.parse(response.analysis);
            } catch (e) {
                showModalError(strings.errorgeneric);
                button.disabled = false;
                button.textContent = originalLabel;
                return null;
            }
            button.textContent = originalLabel;
            row.appendChild(buildResultPanel(analysis, response.maxgrade));
            return null;
        }).catch(function(err) {
            button.disabled = false;
            button.textContent = originalLabel;
            showModalError((err && err.message) || strings.errorgeneric);
        });
    }

    /**
     * Render the items awaiting grading into the modal.
     *
     * @param {Array} items Items from the 'list' action.
     */
    function renderItems(items) {
        var status = document.getElementById('grading-assist-status');
        var list = document.getElementById('grading-assist-items');
        list.innerHTML = '';

        if (!items || !items.length) {
            status.textContent = strings.noitems;
            return;
        }
        status.textContent = '';

        items.forEach(function(item) {
            var row = document.createElement('div');
            row.className = 'mastermind-grading-item';

            var meta = '';
            if (item.question_name) {
                meta = item.question_name;
            } else if (item.has_file && item.filename) {
                meta = item.filename;
            }

            var header = document.createElement('div');
            header.className = 'mastermind-grading-item-header';
            header.innerHTML = '<span class="mastermind-grading-item-label"><strong>' +
                escapeHtml(item.label) + '</strong>' +
                (meta ? ' <small>' + escapeHtml(meta) + '</small>' : '') + '</span>';

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'mastermind-secondary-button mastermind-grading-analyze-btn';
            button.textContent = strings.analyzebutton;

            if (!item.has_text && !item.has_file) {
                button.disabled = true;
                button.title = strings.errornothing;
            }

            button.addEventListener('click', function() {
                analyzeItem(row, item.id, button);
            });

            header.appendChild(button);
            row.appendChild(header);
            list.appendChild(row);
        });
    }

    /**
     * Open the grading assistant modal and load the items list.
     */
    function openModal() {
        var modal = document.getElementById('grading-assist-modal');
        if (!modal) {
            return;
        }
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        modal.style.display = 'flex';

        var status = document.getElementById('grading-assist-status');
        status.textContent = strings.loading;
        document.getElementById('grading-assist-items').innerHTML = '';

        callService('list').then(function(response) {
            if (!response.success) {
                status.textContent = response.error || strings.errorgeneric;
                return null;
            }
            renderItems(response.items || []);
            return null;
        }).catch(function(err) {
            status.textContent = strings.errorgeneric;
            Notification.exception(err);
        });
    }

    /**
     * Close the grading assistant modal.
     */
    function closeModal() {
        var modal = document.getElementById('grading-assist-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    /**
     * Load all language strings used by the module.
     *
     * @returns {Promise} Resolving when strings are cached.
     */
    function loadStrings() {
        var requests = [
            {key: 'grading_analyze_button', component: 'block_mastermind_assistant'},
            {key: 'grading_analyzing', component: 'block_mastermind_assistant'},
            {key: 'grading_confidence_high', component: 'block_mastermind_assistant'},
            {key: 'grading_confidence_low', component: 'block_mastermind_assistant'},
            {key: 'grading_confidence_medium', component: 'block_mastermind_assistant'},
            {key: 'grading_copied_toast', component: 'block_mastermind_assistant'},
            {key: 'grading_copy_failed', component: 'block_mastermind_assistant'},
            {key: 'grading_copy_feedback', component: 'block_mastermind_assistant'},
            {key: 'grading_disclaimer', component: 'block_mastermind_assistant'},
            {key: 'grading_draft_feedback_label', component: 'block_mastermind_assistant'},
            {key: 'grading_error_generic', component: 'block_mastermind_assistant'},
            {key: 'grading_error_nothing_to_analyze', component: 'block_mastermind_assistant'},
            {key: 'grading_gaps_label', component: 'block_mastermind_assistant'},
            {key: 'grading_insert_feedback', component: 'block_mastermind_assistant'},
            {key: 'grading_inserted_toast', component: 'block_mastermind_assistant'},
            {key: 'grading_loading_items', component: 'block_mastermind_assistant'},
            {key: 'grading_no_items', component: 'block_mastermind_assistant'},
            {key: 'grading_no_target_toast', component: 'block_mastermind_assistant'},
            {key: 'grading_strengths_label', component: 'block_mastermind_assistant'},
            {key: 'grading_suggested_label', component: 'block_mastermind_assistant'},
            {key: 'grading_summary_label', component: 'block_mastermind_assistant'}
        ];
        return Str.get_strings(requests).then(function(results) {
            strings.analyzebutton = results[0];
            strings.analyzing = results[1];
            strings.confidencehigh = results[2];
            strings.confidencelow = results[3];
            strings.confidencemedium = results[4];
            strings.copiedtoast = results[5];
            strings.copyfailed = results[6];
            strings.copybutton = results[7];
            strings.disclaimer = results[8];
            strings.feedbacklabel = results[9];
            strings.errorgeneric = results[10];
            strings.errornothing = results[11];
            strings.gapslabel = results[12];
            strings.insertbutton = results[13];
            strings.insertedtoast = results[14];
            strings.loading = results[15];
            strings.noitems = results[16];
            strings.notargettoast = results[17];
            strings.strengthslabel = results[18];
            strings.suggestedlabel = results[19];
            strings.summarylabel = results[20];
            return null;
        });
    }

    /**
     * Initialize the grading assistant on a grading page.
     *
     * @param {number} pagecmid Course module ID.
     * @param {string} kind 'assign' or 'quiz'.
     */
    function init(pagecmid, kind) {
        cmid = pagecmid;
        modtype = kind;

        loadStrings().catch(Notification.exception);

        var openBtn = document.getElementById('mastermind-grading-open-btn');
        if (openBtn) {
            openBtn.addEventListener('click', function() {
                AiPolicy.checkAndProceed(openModal);
            });
        }

        var closeBtn = document.getElementById('grading-assist-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
        var cancelBtn = document.getElementById('grading-assist-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }
        var modal = document.getElementById('grading-assist-modal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
        }
    }

    return {
        init: init
    };
});
