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
 * AMD module for the AI report builder.
 *
 * Shown on course report pages. Two-pane modal: left pane shows example
 * requests and, after generation, the result table (capped at 100 displayed
 * rows) with XLSX/CSV/PDF download buttons; right pane is the chat panel
 * where each generation/refinement uses 1 report action. The conversation
 * and the current validated SQL live in module memory for the lifetime of
 * the modal. All cell values are rendered with textContent — never raw HTML.
 *
 * @module     block_mastermind_assistant/report_builder
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str', 'block_mastermind_assistant/ai_policy'],
function(Ajax, Notification, Str, AiPolicy) {

    var DISPLAY_ROW_CAP = 100;
    var MAX_CONVERSATION_ENTRIES = 8;

    var courseid = 0;
    var strings = {};

    // Modal-lifetime state: conversation plus the current validated result.
    var conversation = [];
    var currentSql = '';
    var currentTitle = '';

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
     * Show or replace the dismissible error banner inside the report modal.
     *
     * Moodle toasts render behind the open modal, so route generation/refine
     * errors into a banner at the top of the modal body instead. Falls back to
     * a toast when the modal isn't open.
     *
     * @param {string} message Error message text.
     */
    function showModalError(message) {
        var modal = document.getElementById('report-builder-modal');
        var body = modal && modal.querySelector('.mastermind-preview-body');
        if (!modal || modal.style.display === 'none' || !body) {
            toast(message, 'error');
            return;
        }

        clearModalError();

        var banner = document.createElement('div');
        banner.id = 'report-builder-error';
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
     * Remove the report modal's error banner if present.
     */
    function clearModalError() {
        var existing = document.getElementById('report-builder-error');
        if (existing) {
            existing.remove();
        }
    }

    /**
     * Append a chat bubble to the request history panel.
     *
     * @param {string} role 'user' or 'assistant'.
     * @param {string} text Bubble text (rendered with textContent).
     */
    function addHistoryEntry(role, text) {
        var history = document.getElementById('report-builder-history');
        if (!history) {
            return;
        }
        var bubble = document.createElement('div');
        bubble.className = 'mastermind-report-msg mastermind-report-msg-' + role;
        bubble.textContent = text;
        history.appendChild(bubble);
        history.scrollTop = history.scrollHeight;
    }

    /**
     * Remember a conversation turn, capped at the most recent entries.
     *
     * @param {string} role 'user' or 'assistant'.
     * @param {string} content Turn content.
     */
    function pushConversation(role, content) {
        conversation.push({role: role, content: content});
        if (conversation.length > MAX_CONVERSATION_ENTRIES) {
            conversation = conversation.slice(-MAX_CONVERSATION_ENTRIES);
        }
    }

    /**
     * Render the result table into the left pane.
     *
     * Cell values come from AI-generated SQL results and are always set via
     * textContent so they can never be interpreted as HTML.
     *
     * @param {Object} response Successful generate_report response.
     */
    function renderResult(response) {
        var examples = document.getElementById('report-builder-examples');
        var output = document.getElementById('report-builder-output');
        var tableWrap = document.getElementById('report-builder-table-wrap');
        var rowcount = document.getElementById('report-builder-rowcount');
        var downloads = document.getElementById('report-builder-downloads');

        examples.style.display = 'none';
        output.style.display = 'block';

        document.getElementById('report-builder-output-title').textContent = response.title || '';
        document.getElementById('report-builder-output-explanation').textContent = response.explanation || '';

        tableWrap.innerHTML = '';
        rowcount.textContent = '';

        var rows = response.rows || [];
        var total = response.total_rows || rows.length;

        if (!rows.length) {
            var empty = document.createElement('p');
            empty.className = 'mastermind-report-empty';
            empty.textContent = strings.norows;
            tableWrap.appendChild(empty);
            downloads.style.display = 'none';
            return;
        }

        var table = document.createElement('table');
        table.className = 'mastermind-report-table';

        var thead = document.createElement('thead');
        var headRow = document.createElement('tr');
        (response.columns || []).forEach(function(column) {
            var th = document.createElement('th');
            th.textContent = column.label || column.key;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        var shown = Math.min(rows.length, DISPLAY_ROW_CAP);
        rows.slice(0, shown).forEach(function(row) {
            var tr = document.createElement('tr');
            row.forEach(function(cell) {
                var td = document.createElement('td');
                td.textContent = cell;
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        tableWrap.appendChild(table);

        if (total > shown) {
            rowcount.textContent = strings.showingrows
                .replace('{$a->shown}', shown)
                .replace('{$a->total}', total);
        } else {
            rowcount.textContent = strings.totalrows.replace('{$a}', total);
        }

        downloads.style.display = 'flex';
    }

    /**
     * Generate or refine a report (1 report action).
     */
    function generate() {
        var input = document.getElementById('report-builder-input');
        var button = document.getElementById('report-builder-generate-btn');
        var request = input.value.trim();
        if (!request || button.disabled) {
            return;
        }

        button.disabled = true;
        var originalLabel = button.textContent;
        button.textContent = strings.generating;
        clearModalError();

        Ajax.call([{
            methodname: 'block_mastermind_assistant_generate_report',
            args: {
                courseid: courseid,
                request: request,
                conversation: JSON.stringify(conversation),
                // The web service parameter is snake_case by contract.
                // eslint-disable-next-line camelcase
                previous_sql: currentSql
            }
        }])[0].then(function(response) {
            button.disabled = false;
            button.textContent = originalLabel;

            if (!response.success) {
                showModalError(response.message || strings.errorgeneric);
                return null;
            }

            currentSql = response.sql || '';
            currentTitle = response.title || '';

            pushConversation('user', request);
            addHistoryEntry('user', request);
            var assistantSummary = (response.title || '') +
                (response.explanation ? ' — ' + response.explanation : '');
            pushConversation('assistant', assistantSummary);
            addHistoryEntry('assistant', assistantSummary);

            input.value = '';
            renderResult(response);
            return null;
        }).catch(function(err) {
            button.disabled = false;
            button.textContent = originalLabel;
            showModalError((err && err.message) || strings.errorgeneric);
        });
    }

    /**
     * POST the current validated SQL to the export endpoint.
     *
     * The server re-validates the SQL through the same gate and re-executes
     * it with the server-bound course id; this form only replays what the
     * server handed out.
     *
     * @param {string} format 'xlsx', 'csv' or 'pdf'.
     */
    function submitExport(format) {
        if (!currentSql) {
            return;
        }
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = M.cfg.wwwroot + '/blocks/mastermind_assistant/export_report.php';
        form.style.display = 'none';

        var fields = {
            sesskey: M.cfg.sesskey,
            courseid: courseid,
            sql: currentSql,
            format: format,
            title: currentTitle
        };
        Object.keys(fields).forEach(function(name) {
            var field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            field.value = fields[name];
            form.appendChild(field);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    /**
     * Open the report builder modal.
     */
    function openModal() {
        var modal = document.getElementById('report-builder-modal');
        if (!modal) {
            return;
        }
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        modal.style.display = 'flex';
        var input = document.getElementById('report-builder-input');
        if (input) {
            input.focus();
        }
    }

    /**
     * Close the report builder modal (state is kept for re-opening).
     */
    function closeModal() {
        var modal = document.getElementById('report-builder-modal');
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
            {key: 'report_error_generic', component: 'block_mastermind_assistant'},
            {key: 'report_generating', component: 'block_mastermind_assistant'},
            {key: 'report_no_rows', component: 'block_mastermind_assistant'},
            {key: 'report_showing_rows', component: 'block_mastermind_assistant'},
            {key: 'report_total_rows', component: 'block_mastermind_assistant'}
        ];
        return Str.get_strings(requests).then(function(results) {
            strings.errorgeneric = results[0];
            strings.generating = results[1];
            strings.norows = results[2];
            strings.showingrows = results[3];
            strings.totalrows = results[4];
            return null;
        });
    }

    /**
     * Initialize the report builder on a course report page.
     *
     * @param {number} pagecourseid Course ID.
     */
    function init(pagecourseid) {
        courseid = pagecourseid;

        loadStrings().catch(Notification.exception);

        var openBtn = document.getElementById('mastermind-report-open-btn');
        if (openBtn) {
            openBtn.addEventListener('click', function() {
                AiPolicy.checkAndProceed(openModal);
            });
        }

        var closeBtn = document.getElementById('report-builder-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
        var cancelBtn = document.getElementById('report-builder-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }
        var modal = document.getElementById('report-builder-modal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
        }

        var generateBtn = document.getElementById('report-builder-generate-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', generate);
        }

        var examples = document.querySelectorAll('.mastermind-report-example');
        for (var i = 0; i < examples.length; i++) {
            examples[i].addEventListener('click', function(e) {
                var input = document.getElementById('report-builder-input');
                if (input) {
                    input.value = e.currentTarget.textContent.trim();
                    input.focus();
                }
            });
        }

        var downloadBtns = document.querySelectorAll('.mastermind-report-download-btn');
        for (var j = 0; j < downloadBtns.length; j++) {
            downloadBtns[j].addEventListener('click', function(e) {
                submitExport(e.currentTarget.getAttribute('data-format'));
            });
        }
    }

    return {
        init: init
    };
});
