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
 * Tests for the disconnect external function.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

/**
 * Tests for the disconnect external function.
 *
 * @covers \block_mastermind_assistant\external\disconnect
 * @runTestsInSeparateProcesses
 */
final class disconnect_test extends \advanced_testcase {
    public function test_disconnect_clears_api_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('api_key', 'ma_live_test123456789012', 'block_mastermind_assistant');

        $result = disconnect::execute();

        $this->assertTrue($result['success']);
        $this->assertFalse(get_config('block_mastermind_assistant', 'api_key'));
    }

    public function test_disconnect_succeeds_when_no_key_saved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // No key to clear — should still succeed (idempotent).
        $result = disconnect::execute();

        $this->assertTrue($result['success']);
    }

    public function test_disconnect_requires_site_config(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        disconnect::execute();
    }
}
