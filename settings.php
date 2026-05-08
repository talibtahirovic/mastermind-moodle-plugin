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
 * Settings page for Mastermind Assistant
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

// Define helper before its first use, guarded against redeclaration on re-includes
// (Moodle includes settings.php multiple times during admin tree builds and on
// PHPUnit init).
if (!function_exists('block_mastermind_assistant_render_connect_card')) {
    /**
     * Render the connect card HTML for the settings page.
     *
     * @param bool $isconnected Whether a valid-looking API key is currently saved.
     * @param string $apikey Raw saved key (used for redacted display only).
     * @return string HTML.
     */
    function block_mastermind_assistant_render_connect_card(bool $isconnected, string $apikey): string {
        $out = '<div class="mastermind-connect-card" '
            . 'style="padding:1rem;border:1px solid #dee2e6;border-radius:6px;background:#f8f9fa;">';
        $out .= '<p style="margin-bottom:1rem;color:#495057;">'
            . s(get_string('connect_card_subtitle', 'block_mastermind_assistant'))
            . '</p>';

        if ($isconnected) {
            // Connected state.
            $redacted = substr($apikey, 0, 11) . str_repeat('•', 8); // Format: ma_live_xxxx••••••••.
            $out .= '<div id="mastermind-connect-status-connected">';
            $out .= '<p style="margin-bottom:0.25rem;font-weight:600;color:#28a745;">'
                . s(get_string('connect_status_connected', 'block_mastermind_assistant'))
                . '</p>';
            $out .= '<p style="margin-bottom:0.5rem;font-size:0.9em;color:#666;">'
                . s(get_string('settings_apikey_redacted', 'block_mastermind_assistant', $redacted))
                . '</p>';
            $out .= '<button type="button" class="btn btn-outline-secondary btn-sm"'
                . ' id="mastermind-disconnect-btn" data-mm-action="disconnect">'
                . s(get_string('connect_disconnect', 'block_mastermind_assistant'))
                . '</button>';
            $out .= ' <button type="button" class="btn btn-link btn-sm"'
                . ' id="mastermind-edit-key-btn" data-mm-action="edit-key">'
                . s(get_string('connect_edit_key', 'block_mastermind_assistant'))
                . '</button>';
            $out .= '</div>';
        } else {
            // Disconnected state — primary CTA + manual paste disclosure.
            $out .= '<div id="mastermind-connect-status-disconnected">';
            $out .= '<p style="margin-bottom:0.5rem;color:#666;">'
                . s(get_string('connect_not_yet', 'block_mastermind_assistant'))
                . '</p>';
            $out .= '<button type="button" class="btn btn-primary"'
                . ' id="mastermind-connect-btn" data-mm-action="connect">'
                . s(get_string('connect_card_title', 'block_mastermind_assistant'))
                . '</button>';
            $out .= '<div id="mastermind-connect-fallback" hidden '
                . 'style="margin-top:0.5rem;font-size:0.85em;color:#666;"></div>';
            $out .= '<p style="margin-top:0.75rem;font-size:0.9em;">'
                . s(get_string('connect_have_key', 'block_mastermind_assistant'))
                . ' <a href="#" id="mastermind-paste-manually-link" data-mm-action="paste-manually">'
                . s(get_string('connect_manual_paste_link', 'block_mastermind_assistant'))
                . '</a></p>';
            $out .= '</div>';
        }

        $out .= '</div>';
        return $out;
    }
}

if ($ADMIN->fulltree) {
    global $PAGE;

    $apikey = get_config('block_mastermind_assistant', 'api_key') ?: '';
    $isconnected = strpos($apikey, 'ma_live_') === 0 && strlen($apikey) >= 20;

    // 1. Connect card — primary activation surface.
    $connecthtml = block_mastermind_assistant_render_connect_card($isconnected, $apikey);
    $settings->add(new admin_setting_heading(
        'block_mastermind_assistant/connect_heading',
        get_string('connect_card_title', 'block_mastermind_assistant'),
        $connecthtml
    ));

    // 2. API Key field (JS hides it unless the user explicitly chooses to paste manually or edit an existing key).
    $settings->add(new admin_setting_configtext(
        'block_mastermind_assistant/api_key',
        get_string('settings_api_key', 'block_mastermind_assistant'),
        get_string('settings_api_key_desc', 'block_mastermind_assistant'),
        ''
    ));

    // 3. Info heading — account & support.
    $infohtml = '<div style="margin-top: 0.5rem;">';
    $infohtml .= '<p>' . get_string('settings_register_desc', 'block_mastermind_assistant') . ' ';
    $infohtml .= '<a href="https://mastermindassistant.ai" target="_blank" rel="noopener">';
    $infohtml .= 'mastermindassistant.ai</a></p>';
    $infohtml .= '<p>' . get_string('settings_support_desc', 'block_mastermind_assistant') . ' ';
    $infohtml .= '<a href="mailto:info@mastermindassistant.ai">info@mastermindassistant.ai</a></p>';
    $infohtml .= '</div>';

    $settings->add(new admin_setting_heading(
        'block_mastermind_assistant/info_heading',
        get_string('settings_info_heading', 'block_mastermind_assistant'),
        $infohtml
    ));

    // 4. Test Connection button.
    $testhtml = '<div id="mastermind-settings-info" style="margin-top: 1rem;">';
    $testhtml .= '<p style="margin-bottom: 0.5rem; color: #666; font-size: 0.85em;">';
    $testhtml .= get_string('test_connection_desc', 'block_mastermind_assistant');
    $testhtml .= '</p>';
    $testhtml .= '<button type="button" id="mastermind-test-connection" class="btn btn-secondary">';
    $testhtml .= get_string('test_connection', 'block_mastermind_assistant');
    $testhtml .= '</button>';
    $testhtml .= '<div id="mastermind-connection-result" style="margin-top: 0.75rem;"></div>';
    $testhtml .= '</div>';

    $settings->add(new admin_setting_heading(
        'block_mastermind_assistant/connection_heading',
        get_string('connection_status', 'block_mastermind_assistant'),
        $testhtml
    ));

    // Mount the settings JS module — handles connect button + paste/edit toggles.
    $callbackurl = (new moodle_url(
        '/admin/settings.php',
        ['section' => 'blocksettingmastermind_assistant']
    ))->out(false);
    $PAGE->requires->js_call_amd(
        'block_mastermind_assistant/settings',
        'init',
        [$callbackurl, $isconnected]
    );
}
