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
 * Tests for the refine_analysis external function.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

/**
 * Tests for the refine_analysis external function.
 *
 * The dashboard HTTP call itself is not exercised here (the api_client has no
 * HTTP mocking layer); these tests cover payload validation, conversation
 * normalization, and capability enforcement.
 *
 * @covers \block_mastermind_assistant\external\refine_analysis
 * @runTestsInSeparateProcesses
 */
final class refine_analysis_test extends \advanced_testcase {
    /**
     * Build a minimal valid refinement payload.
     *
     * @param array $overrides Keys to override in the payload.
     * @return string JSON-encoded payload.
     */
    private function valid_payload(array $overrides = []): string {
        $payload = array_merge([
            'course' => ['id' => 1, 'fullname' => 'Test course'],
            'sections' => [],
            'activities' => [],
            'previous_result' => [
                'recommendations' => 'Add a quiz',
                'structure' => '{"sections":[]}',
            ],
            'conversation' => [],
            'feedback' => 'Make section two more practical',
        ], $overrides);
        return json_encode($payload);
    }

    public function test_validate_payload_rejects_invalid_json(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid refinement payload JSON');
        refine_analysis::validate_payload('not-json{');
    }

    public function test_validate_payload_requires_feedback(): void {
        $payload = json_decode($this->valid_payload(), true);
        $payload['feedback'] = '   ';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Refinement feedback must not be empty');
        refine_analysis::validate_payload(json_encode($payload));
    }

    public function test_validate_payload_requires_previous_result(): void {
        $payload = json_decode($this->valid_payload(), true);
        unset($payload['previous_result']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('previous_result');
        refine_analysis::validate_payload(json_encode($payload));
    }

    public function test_validate_payload_returns_normalized_structure(): void {
        $result = refine_analysis::validate_payload($this->valid_payload([
            'feedback' => '  Trim me  ',
        ]));

        $this->assertSame('Trim me', $result['feedback']);
        $this->assertSame(['id' => 1, 'fullname' => 'Test course'], $result['course']);
        $this->assertSame([], $result['conversation']);
        $this->assertArrayHasKey('previous_result', $result);
    }

    public function test_normalize_conversation_caps_at_eight_entries(): void {
        $conversation = [];
        for ($i = 1; $i <= 12; $i++) {
            $role = ($i % 2 === 1) ? 'user' : 'assistant';
            $conversation[] = ['role' => $role, 'content' => 'entry ' . $i];
        }

        $normalized = refine_analysis::normalize_conversation($conversation);

        $this->assertCount(8, $normalized);
        // Oldest entries are dropped, most recent kept.
        $this->assertSame('entry 5', $normalized[0]['content']);
        $this->assertSame('entry 12', $normalized[7]['content']);
    }

    public function test_normalize_conversation_drops_malformed_entries(): void {
        $normalized = refine_analysis::normalize_conversation([
            ['role' => 'user', 'content' => 'valid'],
            ['role' => '', 'content' => 'missing role'],
            ['role' => 'assistant'],
            'not-an-array',
            ['role' => 'assistant', 'content' => ['not' => 'a string']],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame('valid', $normalized[0]['content']);
    }

    public function test_execute_returns_error_for_invalid_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = refine_analysis::execute($course->id, 'not-json{');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid refinement payload JSON', $result['error']);
    }

    public function test_execute_denies_users_without_view_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $result = refine_analysis::execute($course->id, $this->valid_payload());
        $this->assertDebuggingCalled();

        // Capability failures surface through the standard error envelope.
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('Invalid refinement payload', $result['error']);
    }

    public function test_execute_allows_teacher_but_fails_without_api_key(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);

        // Payload validation passes; the api_client constructor then fails
        // because no API key is configured — proving the capability gate
        // admits editing teachers.
        $result = refine_analysis::execute($course->id, $this->valid_payload());
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('AI Refinement Error', $result['error']);
        $this->assertStringNotContainsString('Invalid refinement payload', $result['error']);
    }
}
