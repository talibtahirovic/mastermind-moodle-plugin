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
 * Tests for the Mastermind Assistant API client.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant;

/**
 * Tests for the api_client URL resolution and configuration handling.
 *
 * @covers \block_mastermind_assistant\api_client
 */
final class api_client_test extends \advanced_testcase {
    public function test_dashboard_url_uses_constant_by_default(): void {
        $this->resetAfterTest();
        // No persisted setting, no forced override.
        $this->assertEquals(
            'https://mastermindassistant.ai',
            api_client::get_dashboard_url()
        );
    }

    public function test_dashboard_url_strips_trailing_slash_from_constant(): void {
        $this->resetAfterTest();
        $this->assertStringEndsNotWith('/', api_client::get_dashboard_url());
    }

    public function test_dashboard_url_respects_persisted_setting(): void {
        $this->resetAfterTest();
        set_config('dashboard_url', 'https://staging.example.org/', 'block_mastermind_assistant');

        $this->assertEquals(
            'https://staging.example.org',
            api_client::get_dashboard_url()
        );
    }

    public function test_dashboard_url_respects_forced_plugin_settings(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings = $CFG->forced_plugin_settings ?? [];
        $CFG->forced_plugin_settings['block_mastermind_assistant'] = [
            'dashboard_url' => 'https://forced.example.org',
        ];

        $this->assertEquals(
            'https://forced.example.org',
            api_client::get_dashboard_url()
        );
    }

    public function test_dashboard_url_strips_trailing_slash_from_forced_override(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->forced_plugin_settings = $CFG->forced_plugin_settings ?? [];
        $CFG->forced_plugin_settings['block_mastermind_assistant'] = [
            'dashboard_url' => 'https://forced.example.org/',
        ];

        $this->assertEquals(
            'https://forced.example.org',
            api_client::get_dashboard_url()
        );
    }

    public function test_constructor_throws_without_api_key(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        new api_client();
    }

    public function test_constructor_succeeds_with_just_api_key(): void {
        $this->resetAfterTest();
        set_config('api_key', 'ma_live_test_value', 'block_mastermind_assistant');

        // Should NOT throw — constructor only requires api_key now (URL has constant fallback).
        $client = new api_client();
        $this->assertInstanceOf(api_client::class, $client);
    }
}
