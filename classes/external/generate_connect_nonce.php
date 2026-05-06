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
 * External function to generate a nonce + connect URL for the simplified setup flow.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Generate a single-use nonce and the corresponding connect URL.
 *
 * The nonce is stored in the user's Moodle session and validated by callback.php
 * when the dashboard redirects back. The flow is initiated via AJAX from the
 * locked-state block when an admin clicks "Connect to Mastermind".
 */
class generate_connect_nonce extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'returnurl' => new external_value(
                PARAM_LOCALURL,
                'Local URL to return to after connection',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Generate a fresh nonce, store it in the session, and build the connect URL.
     *
     * @param string $returnurl Local Moodle URL to redirect to after the callback.
     * @return array Array containing the connect_url and the generated nonce.
     */
    public static function execute($returnurl = '') {
        global $CFG, $SESSION, $USER;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'returnurl' => $returnurl,
        ]);

        $nonce = bin2hex(random_bytes(16));
        $SESSION->mastermind_connect_nonce = $nonce;

        $cleanreturn = $params['returnurl'];
        if (empty($cleanreturn) || strpos($cleanreturn, '/') !== 0) {
            $cleanreturn = '/my/';
        }
        $SESSION->mastermind_connect_return = $cleanreturn;

        $callbackurl = $CFG->wwwroot . '/blocks/mastermind_assistant/callback.php';
        $dashboardbase = \block_mastermind_assistant\api_client::get_dashboard_url();

        // Pre-fill the dashboard signup form with the connecting admin's details
        // so the user only has to confirm and click — no retyping required.
        $sitename = format_string(get_site()->fullname ?? '', true);
        $email = !empty($USER->email) ? $USER->email : '';
        $fullname = trim(fullname($USER));

        $connectparams = [
            'callback_url' => $callbackurl,
            'nonce' => $nonce,
            'site_url' => $CFG->wwwroot,
            'site_name' => $sitename,
        ];
        if ($email !== '') {
            $connectparams['email'] = $email;
        }
        if ($fullname !== '') {
            $connectparams['name'] = $fullname;
        }

        $connecturl = $dashboardbase . '/connect?' . http_build_query($connectparams, '', '&', PHP_QUERY_RFC3986);

        return [
            'connect_url' => $connecturl,
            'nonce' => $nonce,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'connect_url' => new external_value(PARAM_URL, 'Full URL to open for the connect flow'),
            'nonce' => new external_value(PARAM_ALPHANUMEXT, 'The nonce stored in session'),
        ]);
    }
}
