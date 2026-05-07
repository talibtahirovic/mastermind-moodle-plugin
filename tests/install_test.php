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
 * Tests for the install hook.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/mastermind_assistant/db/install.php');

/**
 * @covers ::xmldb_block_mastermind_assistant_install
 */
class install_test extends \advanced_testcase {

    public function test_install_creates_sticky_block_instance(): void {
        global $DB;
        $this->resetAfterTest();

        // Remove any existing sticky block from previous test runs.
        $DB->delete_records('block_instances', ['blockname' => 'mastermind_assistant']);

        xmldb_block_mastermind_assistant_install();

        $instances = $DB->get_records('block_instances', [
            'blockname' => 'mastermind_assistant',
        ]);
        $this->assertCount(1, $instances);

        $instance = reset($instances);
        $this->assertEquals(SYSCONTEXTID, $instance->parentcontextid);
        $this->assertEquals(1, (int) $instance->showinsubcontexts);
        $this->assertEquals('side-pre', $instance->defaultregion);
    }

    public function test_install_sends_admin_notification(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();

        // Reset state so the helper actually fires.
        \block_mastermind_assistant\local\setup_helper::reset_setup_state();

        $sink = $this->redirectMessages();
        xmldb_block_mastermind_assistant_install();
        $messages = $sink->get_messages();
        $sink->close();

        $admins = get_admins();
        $this->assertCount(count($admins), $messages);
        $this->assertEquals('block_mastermind_assistant', $messages[0]->component);
        $this->assertEquals('setup_required', $messages[0]->eventtype);
    }

    public function test_install_silent_on_ping_failure(): void {
        global $CFG;
        $this->resetAfterTest();

        // Force the dashboard URL to a guaranteed-unroutable target so the
        // ping curl call fails fast.
        $CFG->forced_plugin_settings = $CFG->forced_plugin_settings ?? [];
        $CFG->forced_plugin_settings['block_mastermind_assistant'] = [
            'dashboard_url' => 'http://127.0.0.1:1', // guaranteed connection refused
        ];

        // Should not throw despite the unreachable endpoint.
        $result = xmldb_block_mastermind_assistant_install();
        $this->assertTrue($result);
    }

    public function test_install_does_not_create_duplicate_sticky_block(): void {
        global $DB;
        $this->resetAfterTest();

        // Pre-create a sticky block.
        $DB->delete_records('block_instances', ['blockname' => 'mastermind_assistant']);
        xmldb_block_mastermind_assistant_install();
        $countbefore = $DB->count_records('block_instances', [
            'blockname' => 'mastermind_assistant',
            'showinsubcontexts' => 1,
        ]);

        // Re-run install — should not create a second sticky block.
        xmldb_block_mastermind_assistant_install();
        $countafter = $DB->count_records('block_instances', [
            'blockname' => 'mastermind_assistant',
            'showinsubcontexts' => 1,
        ]);

        $this->assertEquals($countbefore, $countafter);
        $this->assertEquals(1, $countafter);
    }
}
