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
 * Tests for the complete_setup external function.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

/**
 * Tests for the complete_setup external function.
 *
 * @covers \block_mastermind_assistant\external\complete_setup
 * @runTestsInSeparateProcesses
 */
final class complete_setup_test extends \advanced_testcase {
    public function test_marks_setup_complete(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \block_mastermind_assistant\local\setup_helper::reset_setup_state();

        $result = complete_setup::execute();

        $this->assertTrue($result['success']);
        $this->assertTrue(\block_mastermind_assistant\local\setup_helper::is_setup_complete());
    }

    public function test_idempotent_on_repeated_calls(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \block_mastermind_assistant\local\setup_helper::reset_setup_state();

        complete_setup::execute();
        $second = complete_setup::execute();

        $this->assertTrue($second['success']);
        $this->assertTrue(\block_mastermind_assistant\local\setup_helper::is_setup_complete());
    }

    public function test_requires_site_config(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        complete_setup::execute();
    }
}
