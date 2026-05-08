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
 * Settings page module for Mastermind Assistant.
 *
 * Coordinates the Connect button, manual-paste disclosure, Disconnect button,
 * and the existing Test Connection flow.
 *
 * @module     block_mastermind_assistant/settings
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/templates',
    'block_mastermind_assistant/connect'
], function(Ajax, Notification, Str, Templates, Connect) {

    /**
     * Hide the API Key admin row by default.
     *
     * @return {?HTMLElement} The hidden row, or null if it could not be found.
     */
    function hideApiKeyRow() {
        var row = document.getElementById('admin-api_key');
        if (row) {
            row.style.display = 'none';
        }
        return row;
    }

    /**
     * Show the API Key row (used when the user clicks "Paste manually" or "Edit key").
     */
    function showApiKeyRow() {
        var row = document.getElementById('admin-api_key');
        if (row) {
            row.style.display = '';
            var input = row.querySelector('input[type="text"], input[type="password"]');
            if (input) {
                input.focus();
            }
        }
    }

    /**
     * Show the disconnect confirmation modal with the given strings.
     *
     * @param {Array} strings Translated strings array [title, body, confirmLabel].
     * @param {HTMLElement} btn The disconnect button.
     */
    function showDisconnectConfirm(strings, btn) {
        Notification.saveCancel(strings[0], strings[1], strings[2], function() {
            performDisconnect(btn);
        }).catch(Notification.exception);
    }

    /**
     * Wire the Disconnect button.
     */
    function wireDisconnectButton() {
        var btn = document.getElementById('mastermind-disconnect-btn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function() {
            Str.get_strings([
                {key: 'connect_disconnect_confirm_title', component: 'block_mastermind_assistant'},
                {key: 'connect_disconnect_confirm_body', component: 'block_mastermind_assistant'},
                {key: 'connect_disconnect', component: 'block_mastermind_assistant'}
            ]).then(function(strings) {
                showDisconnectConfirm(strings, btn);
                return null;
            }).catch(Notification.exception);
        });
    }

    /**
     * Call the disconnect web service then reload the settings page.
     *
     * @param {HTMLElement} btn The disconnect button.
     */
    function performDisconnect(btn) {
        btn.disabled = true;
        Ajax.call([{
            methodname: 'block_mastermind_assistant_disconnect',
            args: {}
        }])[0].then(function() {
            window.location.reload();
            return null;
        }).catch(function(err) {
            btn.disabled = false;
            Notification.exception(err);
        });
    }

    /**
     * Wire the "Paste it manually" disclosure link (disconnected state).
     */
    function wirePasteManually() {
        var link = document.getElementById('mastermind-paste-manually-link');
        if (!link) {
            return;
        }
        link.addEventListener('click', function(e) {
            e.preventDefault();
            showApiKeyRow();
        });
    }

    /**
     * Wire the "Edit / replace" link shown when already connected.
     */
    function wireEditKey() {
        var btn = document.getElementById('mastermind-edit-key-btn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function() {
            showApiKeyRow();
        });
    }

    /**
     * Update the test connection button label from a translated string.
     *
     * @param {HTMLElement} button The test connection button.
     * @param {string} key Lang string key.
     * @param {string} fallback Fallback text if translation fails.
     */
    function setTestButtonLabel(button, key, fallback) {
        Str.get_string(key, 'block_mastermind_assistant').then(function(s) {
            button.textContent = s;
            return null;
        }).catch(function() {
            button.textContent = fallback;
        });
    }

    /**
     * Wire the existing Test Connection button (kept from the previous version).
     */
    function wireTestConnection() {
        var button = document.getElementById('mastermind-test-connection');
        var resultDiv = document.getElementById('mastermind-connection-result');
        if (!button || !resultDiv) {
            return;
        }

        button.addEventListener('click', function() {
            button.disabled = true;
            setTestButtonLabel(button, 'testing_connection', '...');
            Str.get_string('testing_connection', 'block_mastermind_assistant').then(function(s) {
                resultDiv.innerHTML = '<div class="alert alert-info">' + escapeHtml(s) + '</div>';
                return null;
            }).catch(function() {
                resultDiv.innerHTML = '';
            });

            var savedResponse = null;
            Ajax.call([{
                methodname: 'block_mastermind_assistant_test_connection',
                args: {}
            }])[0].then(function(response) {
                button.disabled = false;
                savedResponse = response;
                setTestButtonLabel(button, 'test_connection', 'Test Connection');

                var context = {
                    success: response.success,
                    tier: response.tier || '',
                    status: response.status || '',
                    message: response.message || ''
                };
                return Templates.renderForPromise('block_mastermind_assistant/connection_result', context);
            }).then(function(result) {
                if (result) {
                    resultDiv.innerHTML = result.html;
                }
                return null;
            }).catch(function(error) {
                button.disabled = false;
                var msg = (savedResponse && savedResponse.message) || (error && error.message) || '';
                resultDiv.innerHTML = '<div class="alert alert-danger">' + escapeHtml(msg) + '</div>';
                if (!savedResponse) {
                    Notification.exception(error);
                }
            });
        });
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} text Text to escape.
     * @return {string} Escaped HTML.
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text || ''));
        return div.innerHTML;
    }

    return {
        /**
         * Initialise the settings-page interactions.
         *
         * @param {string} returnUrl Local Moodle URL to return to after callback.
         * @param {boolean} isConnected Whether a valid-looking API key is saved.
         */
        init: function(returnUrl, isConnected) {
            // Always hide the API key row first; user must click paste/edit to reveal it.
            hideApiKeyRow();

            if (!isConnected) {
                Connect.init('mastermind-connect-btn', returnUrl);
                wirePasteManually();
            } else {
                wireDisconnectButton();
                wireEditKey();
            }

            wireTestConnection();
        }
    };
});
