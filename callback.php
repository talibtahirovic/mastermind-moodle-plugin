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

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$key = required_param('key', PARAM_RAW);
$nonce = required_param('nonce', PARAM_ALPHANUMEXT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/mastermind_assistant/callback.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'block_mastermind_assistant'));

$sessionnonce = $SESSION->mastermind_connect_nonce ?? '';
if (empty($sessionnonce) || !hash_equals($sessionnonce, $nonce)) {
    unset($SESSION->mastermind_connect_nonce);
    unset($SESSION->mastermind_connect_return);
    throw new moodle_exception('invalid_nonce', 'block_mastermind_assistant');
}

unset($SESSION->mastermind_connect_nonce);

if (strpos($key, 'ma_live_') !== 0 || strlen($key) < 20) {
    throw new moodle_exception('invalid_api_key_format', 'block_mastermind_assistant');
}

set_config('api_key', $key, 'block_mastermind_assistant');
// dashboard_url is now a constant in api_client::DASHBOARD_URL; do not persist it.

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
