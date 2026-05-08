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
 * Tests for connect_callback validation logic.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Tests for the connect_callback validation logic.
 *
 * @covers \block_mastermind_assistant\local\connect_callback
 */
final class connect_callback_test extends \advanced_testcase {
    public function test_valid_key_format(): void {
        $this->assertTrue(connect_callback::is_valid_key_format('ma_live_abcdef1234567890'));
        $this->assertTrue(connect_callback::is_valid_key_format(
            'ma_live_76e4d74fb548f46f3f498158010d7deadb31eff4b32e7af3e6fa992d8a8c6cfc'
        ));
    }

    public function test_invalid_key_format_wrong_prefix(): void {
        $this->assertFalse(connect_callback::is_valid_key_format('ma_test_abcdef1234567890'));
        $this->assertFalse(connect_callback::is_valid_key_format('not-a-key'));
        $this->assertFalse(connect_callback::is_valid_key_format(''));
    }

    public function test_invalid_key_format_too_short(): void {
        $this->assertFalse(connect_callback::is_valid_key_format('ma_live_short'));
    }

    public function test_validate_success_when_nonce_matches(): void {
        $key = 'ma_live_abcdef1234567890';
        $nonce = 'abc123';
        $sessionnonce = 'abc123';

        $this->assertEquals(
            connect_callback::RESULT_SUCCESS,
            connect_callback::validate($key, $nonce, $sessionnonce)
        );
    }

    public function test_validate_invalid_key_takes_priority_over_nonce(): void {
        $this->assertEquals(
            connect_callback::RESULT_INVALID_KEY,
            connect_callback::validate('not-a-key', 'abc123', 'abc123')
        );
    }

    public function test_validate_recoverable_nonce_mismatch_when_key_is_valid(): void {
        $key = 'ma_live_abcdef1234567890';
        $this->assertEquals(
            connect_callback::RESULT_INVALID_NONCE_RECOVERABLE,
            connect_callback::validate($key, 'wrong_nonce', 'session_nonce')
        );
    }

    public function test_validate_recoverable_when_session_nonce_empty(): void {
        // The user clicked Connect twice — second click consumed the session
        // nonce, dashboard returns with the second nonce, but session was also
        // cleared by an earlier failed callback. Key is still valid.
        $key = 'ma_live_abcdef1234567890';
        $this->assertEquals(
            connect_callback::RESULT_INVALID_NONCE_RECOVERABLE,
            connect_callback::validate($key, 'somenonce', '')
        );
    }

    public function test_validate_uses_constant_time_comparison(): void {
        // hash_equals is constant-time. We just verify the underlying behavior
        // is correct: a partial match should NOT validate (regression guard).
        $key = 'ma_live_abcdef1234567890';
        $this->assertEquals(
            connect_callback::RESULT_INVALID_NONCE_RECOVERABLE,
            connect_callback::validate($key, 'abc12', 'abc123')
        );
    }
}
