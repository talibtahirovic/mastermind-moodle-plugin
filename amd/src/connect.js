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
 * AMD module for the simplified Connect-to-Mastermind flow.
 *
 * Generates a single-use nonce, opens the dashboard's connect URL in a new tab,
 * then polls the test_connection web service every few seconds. Once the
 * callback has saved the API key, the polling call succeeds and the page
 * reloads to render the full block.
 *
 * @module     block_mastermind_assistant/connect
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {

    var POLL_INTERVAL_MS = 3000;
    var POLL_TIMEOUT_MS = 5 * 60 * 1000;

    /**
     * Begin polling the dashboard test-connection web service.
     *
     * @param {HTMLElement} btn The connect button.
     * @param {string} originalLabel The original button label for restoration.
     */
    function startPolling(btn, originalLabel) {
        var elapsed = 0;
        var pollInterval = window.setInterval(function() {
            elapsed += POLL_INTERVAL_MS;

            Ajax.call([{
                methodname: 'block_mastermind_assistant_test_connection',
                args: {}
            }])[0].then(function(result) {
                if (result && result.success) {
                    window.clearInterval(pollInterval);
                    window.location.reload();
                }
                return null;
            }).catch(function() {
                // Connection not ready yet — continue polling silently.
                return null;
            });

            if (elapsed >= POLL_TIMEOUT_MS) {
                window.clearInterval(pollInterval);
                btn.disabled = false;
                btn.textContent = originalLabel;
            }
        }, POLL_INTERVAL_MS);
    }

    /**
     * Render a fallback link block when the popup/new-tab is suspected to be blocked.
     *
     * @param {string} url The connect URL.
     */
    function showFallback(url) {
        var fallback = document.getElementById('mastermind-connect-fallback');
        if (!fallback) {
            return;
        }
        Str.get_string('connect_popup_blocked', 'block_mastermind_assistant').then(function(message) {
            fallback.innerHTML = '';
            var p = document.createElement('p');
            p.textContent = message;
            p.style.margin = '0 0 6px';
            var a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = url;
            fallback.appendChild(p);
            fallback.appendChild(a);
            fallback.hidden = false;
            return null;
        }).catch(function() {
            fallback.textContent = url;
            fallback.hidden = false;
        });
    }

    return {
        /**
         * Initialise a Connect button.
         *
         * @param {string} buttonId DOM id of the button to mount on.
         * @param {string} returnUrl Local Moodle URL to redirect to after the callback.
         */
        init: function(buttonId, returnUrl) {
            var btn = document.getElementById(buttonId || 'mastermind-connect-btn');
            if (!btn) {
                return;
            }

            btn.addEventListener('click', function() {
                var originalLabel = btn.textContent;
                btn.disabled = true;

                Str.get_string('connect_connecting', 'block_mastermind_assistant').then(function(label) {
                    btn.textContent = label;
                    return null;
                }).catch(function() {
                    btn.textContent = '...';
                });

                Ajax.call([{
                    methodname: 'block_mastermind_assistant_generate_connect_nonce',
                    args: {returnurl: returnUrl || ''}
                }])[0].then(function(response) {
                    var connectUrl = response.connect_url;
                    var newWin = window.open(connectUrl, '_blank', 'noopener');

                    if (!newWin || newWin.closed || typeof newWin.closed === 'undefined') {
                        showFallback(connectUrl);
                    }

                    startPolling(btn, originalLabel);
                    return Str.get_string('connect_waiting', 'block_mastermind_assistant');
                }).then(function(label) {
                    btn.textContent = label;
                    return null;
                }).catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    Notification.exception(err);
                });
            });
        }
    };
});
