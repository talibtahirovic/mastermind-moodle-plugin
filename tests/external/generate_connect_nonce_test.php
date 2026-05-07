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
 * Tests for the generate_connect_nonce external function.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \block_mastermind_assistant\external\generate_connect_nonce
 * @runTestsInSeparateProcesses
 */
class generate_connect_nonce_test extends \advanced_testcase {

    public function test_generates_unique_nonce(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $first = generate_connect_nonce::execute('');
        $second = generate_connect_nonce::execute('');

        $this->assertNotEquals($first['nonce'], $second['nonce']);
    }

    public function test_nonce_format(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = generate_connect_nonce::execute('');

        // bin2hex(random_bytes(16)) produces a 32-char lowercase hex string.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['nonce']);
    }

    public function test_connect_url_includes_callback_and_nonce(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = generate_connect_nonce::execute('');

        $expectedcallback = $CFG->wwwroot . '/blocks/mastermind_assistant/callback.php';
        $this->assertStringContainsString(
            'callback_url=' . rawurlencode($expectedcallback),
            $result['connect_url']
        );
        $this->assertStringContainsString(
            'nonce=' . $result['nonce'],
            $result['connect_url']
        );
    }

    public function test_returnurl_falls_back_to_my_when_empty(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();

        generate_connect_nonce::execute('');

        $this->assertEquals('/my/', $SESSION->mastermind_connect_return);
    }

    public function test_returnurl_preserves_local_path(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();

        generate_connect_nonce::execute('/admin/settings.php?section=blocksettingmastermind_assistant');

        $this->assertEquals(
            '/admin/settings.php?section=blocksettingmastermind_assistant',
            $SESSION->mastermind_connect_return
        );
    }

    public function test_requires_site_config_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        generate_connect_nonce::execute('');
    }
}
