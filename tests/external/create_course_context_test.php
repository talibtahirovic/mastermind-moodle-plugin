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
 * Tests for the optional creation context parameter on course creation.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

/**
 * Tests for the optional creation context parameter on course creation.
 *
 * The dashboard HTTP call itself is not exercised (no HTTP mocking layer);
 * these tests pin the external contract: both creation functions accept an
 * optional contextjson parameter that defaults to an empty string, and a
 * malformed context never changes the error envelope behavior.
 *
 * @covers \block_mastermind_assistant\external\create_course_with_ai
 * @covers \block_mastermind_assistant\external\create_course_from_document
 * @runTestsInSeparateProcesses
 */
final class create_course_context_test extends \advanced_testcase {
    public function test_create_course_with_ai_accepts_optional_contextjson(): void {
        $keys = create_course_with_ai::execute_parameters()->keys;

        $this->assertArrayHasKey('contextjson', $keys);
        $this->assertSame(VALUE_DEFAULT, $keys['contextjson']->required);
        $this->assertSame('', $keys['contextjson']->default);
    }

    public function test_create_course_from_document_accepts_optional_contextjson(): void {
        $keys = create_course_from_document::execute_parameters()->keys;

        $this->assertArrayHasKey('contextjson', $keys);
        $this->assertSame(VALUE_DEFAULT, $keys['contextjson']->required);
        $this->assertSame('', $keys['contextjson']->default);
    }

    public function test_execute_with_malformed_contextjson_still_returns_error_envelope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Without a configured dashboard the API call fails either way; the
        // point is that malformed context is swallowed (best-effort) and the
        // failure is the normal dashboard error envelope, not a context error.
        $result = create_course_with_ai::execute('Solar Energy 101', 1, true, 'not-json{');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsStringIgnoringCase('context', $result['error']);
    }
}
