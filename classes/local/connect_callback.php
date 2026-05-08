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
 * Callback validation logic for the simplified connect flow.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Validates the API key + nonce returned by the dashboard.
 *
 * Pure logic, extracted from `callback.php` so the success / nonce-mismatch
 * / invalid-key branches are unit-testable. The `callback.php` script wraps
 * this with the rendering and config-write side effects.
 */
class connect_callback {
    /** Callback succeeded — key is valid and nonce matches. */
    public const RESULT_SUCCESS = 'success';

    /** Key format invalid — must reject (no usable key to recover). */
    public const RESULT_INVALID_KEY = 'invalid_key';

    /**
     * Nonce mismatch but key is format-valid — show recovery page so the
     * admin can paste the key manually instead of being stranded.
     */
    public const RESULT_INVALID_NONCE_RECOVERABLE = 'invalid_nonce_recoverable';

    /**
     * Validate the callback inputs.
     *
     * @param string $key The API key from the dashboard redirect query string.
     * @param string $nonce The nonce from the dashboard redirect query string.
     * @param string $sessionnonce The nonce currently stored in the user's session.
     * @return string One of the RESULT_* constants.
     */
    public static function validate(string $key, string $nonce, string $sessionnonce): string {
        if (!self::is_valid_key_format($key)) {
            return self::RESULT_INVALID_KEY;
        }

        if (empty($sessionnonce) || !hash_equals($sessionnonce, $nonce)) {
            return self::RESULT_INVALID_NONCE_RECOVERABLE;
        }

        return self::RESULT_SUCCESS;
    }

    /**
     * Whether the key matches the expected `ma_live_<at least 12 chars>` format.
     *
     * @param string $key Raw key from the URL.
     * @return bool
     */
    public static function is_valid_key_format(string $key): bool {
        return strpos($key, 'ma_live_') === 0 && strlen($key) >= 20;
    }
}
