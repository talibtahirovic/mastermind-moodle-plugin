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
 * Tests for the setup_helper class.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \block_mastermind_assistant\local\setup_helper
 */
class setup_helper_test extends \advanced_testcase {

    public function test_is_setup_complete_defaults_to_false(): void {
        $this->resetAfterTest();
        $this->assertFalse(setup_helper::is_setup_complete());
    }

    public function test_mark_setup_complete_persists(): void {
        $this->resetAfterTest();
        setup_helper::mark_setup_complete();
        $this->assertTrue(setup_helper::is_setup_complete());
    }

    public function test_reset_setup_state_clears_both_flags(): void {
        $this->resetAfterTest();
        setup_helper::mark_setup_complete();
        // Trigger the notified flag too via send_install_notification (separate test verifies idempotency).
        $sink = $this->redirectMessages();
        setup_helper::send_install_notification();
        $sink->close();

        setup_helper::reset_setup_state();

        $this->assertFalse(setup_helper::is_setup_complete());
        // After reset, send_install_notification can fire again.
        $sink2 = $this->redirectMessages();
        setup_helper::send_install_notification();
        $messages = $sink2->get_messages();
        $sink2->close();
        $this->assertGreaterThan(0, count($messages));
    }

    public function test_send_install_notification_creates_message_for_each_admin(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        setup_helper::reset_setup_state();

        $sink = $this->redirectMessages();
        setup_helper::send_install_notification();
        $messages = $sink->get_messages();
        $sink->close();

        $admins = get_admins();
        $this->assertCount(count($admins), $messages);
        foreach ($messages as $msg) {
            $this->assertEquals('block_mastermind_assistant', $msg->component);
            $this->assertEquals('setup_required', $msg->eventtype);
            $this->assertEquals(1, (int) $msg->notification);
        }
    }

    public function test_send_install_notification_is_idempotent(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        setup_helper::reset_setup_state();

        $sink = $this->redirectMessages();
        setup_helper::send_install_notification();
        setup_helper::send_install_notification(); // second call must not duplicate
        $messages = $sink->get_messages();
        $sink->close();

        $admins = get_admins();
        $this->assertCount(count($admins), $messages);
    }

    public function test_queue_install_notification_queues_adhoc_task(): void {
        global $DB;
        $this->resetAfterTest();
        setup_helper::reset_setup_state();
        $DB->delete_records('task_adhoc');

        setup_helper::queue_install_notification();

        $tasks = $DB->get_records('task_adhoc', [
            'classname' => '\\block_mastermind_assistant\\task\\send_install_notification',
            'component' => 'block_mastermind_assistant',
        ]);
        $this->assertCount(1, $tasks);
    }

    public function test_queue_install_notification_is_idempotent_when_notified_flag_set(): void {
        global $DB;
        $this->resetAfterTest();
        setup_helper::reset_setup_state();
        $DB->delete_records('task_adhoc');

        // Simulate "already notified" state.
        set_config(setup_helper::FLAG_NOTIFIED, '1', setup_helper::COMPONENT);

        setup_helper::queue_install_notification();

        $count = $DB->count_records('task_adhoc', [
            'classname' => '\\block_mastermind_assistant\\task\\send_install_notification',
        ]);
        $this->assertEquals(0, $count);
    }

    public function test_send_install_notification_sets_contexturl_to_settings_page(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        setup_helper::reset_setup_state();

        $sink = $this->redirectMessages();
        setup_helper::send_install_notification();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertNotEmpty($messages);
        $first = $messages[0];
        $this->assertStringContainsString(
            'section=blocksettingmastermind_assistant',
            (string) $first->contexturl
        );
    }
}
