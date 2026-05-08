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
 * OAuth-style callback endpoint for the simplified connect flow.
 *
 * Receives the API key and nonce from mastermindassistant.ai/connect
 * and saves the key into plugin config.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Locate Moodle's config.php. In a normal install __DIR__ resolves to
// <moodle>/blocks/mastermind_assistant/ and ../../config.php works. When the
// plugin is symlinked outside the Moodle tree (dev), the .. traversal through
// the symlink lands outside Moodle, so we derive Moodle's root from the
// request URL instead — no .. traversal involved.
require_once(
    is_file(__DIR__ . '/../../config.php')
        ? __DIR__ . '/../../config.php'
        : rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))) . '/config.php'
);

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$key = required_param('key', PARAM_RAW);
$nonce = required_param('nonce', PARAM_ALPHANUMEXT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/mastermind_assistant/callback.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'block_mastermind_assistant'));

if (strpos($key, 'ma_live_') !== 0 || strlen($key) < 20) {
    throw new moodle_exception('invalid_api_key_format', 'block_mastermind_assistant');
}

$sessionnonce = $SESSION->mastermind_connect_nonce ?? '';
if (empty($sessionnonce) || !hash_equals($sessionnonce, $nonce)) {
    // Nonce mismatch — typically caused by clicking Connect more than once
    // (the second click overwrites the session nonce, but the dashboard
    // still returns with whichever nonce was issued for the user's signup).
    // Don't strand the admin: the key in the URL is valid (format-checked
    // above) and they'll authenticate via require_capability anyway. Show
    // the key with a "Paste it manually" call-to-action and let them
    // recover via the settings page disclosure.
    unset($SESSION->mastermind_connect_nonce);
    unset($SESSION->mastermind_connect_return);

    $settingsurl = new moodle_url('/admin/settings.php', [
        'section' => 'blocksettingmastermind_assistant',
    ]);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'block_mastermind_assistant'));
    echo $OUTPUT->notification(
        get_string('invalid_nonce', 'block_mastermind_assistant'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo html_writer::tag('p',
        get_string('connect_recover_intro', 'block_mastermind_assistant')
    );
    echo html_writer::start_tag('div', [
        'style' => 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;'
            . 'padding:0.75rem;margin:0.75rem 0;font-family:monospace;'
            . 'word-break:break-all;user-select:all;',
    ]);
    echo s($key);
    echo html_writer::end_tag('div');
    echo html_writer::link(
        $settingsurl,
        get_string('connect_recover_open_settings', 'block_mastermind_assistant'),
        ['class' => 'btn btn-primary']
    );
    echo $OUTPUT->footer();
    exit;
}

unset($SESSION->mastermind_connect_nonce);

set_config('api_key', $key, 'block_mastermind_assistant');
// The dashboard_url is now a constant in api_client::DASHBOARD_URL; do not persist it.

$returnurl = $SESSION->mastermind_connect_return ?? '/my/';
unset($SESSION->mastermind_connect_return);

if (!is_string($returnurl) || strpos($returnurl, '/') !== 0) {
    $returnurl = '/my/';
}

redirect(
    new moodle_url($returnurl),
    get_string('connection_success_redirect', 'block_mastermind_assistant'),
    2,
    \core\output\notification::NOTIFY_SUCCESS
);
