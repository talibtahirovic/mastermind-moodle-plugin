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
 * Tests for the refine_course external function.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

/**
 * Tests for the refine_course external function.
 *
 * The dashboard HTTP call itself is not exercised here (the api_client has no
 * HTTP mocking layer); these tests cover payload validation, capability
 * enforcement, and the guarantee that no course is ever created.
 *
 * @covers \block_mastermind_assistant\external\refine_course
 * @runTestsInSeparateProcesses
 */
final class refine_course_test extends \advanced_testcase {
    /**
     * Build a minimal valid refinement payload.
     *
     * @param array $overrides Keys to override in the payload.
     * @return string JSON-encoded payload.
     */
    private function valid_payload(array $overrides = []): string {
        $payload = array_merge([
            'course_name' => 'Intro to Marine Biology',
            'previous_result' => [
                'course_name' => 'Intro to Marine Biology',
                'sections' => [
                    ['section_name' => 'Week 1', 'activities' => []],
                ],
            ],
            'conversation' => [],
            'feedback' => 'Add a section about coral reefs',
        ], $overrides);
        return json_encode($payload);
    }

    public function test_validate_payload_rejects_invalid_json(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid refinement payload JSON');
        refine_course::validate_payload('not-json{');
    }

    public function test_validate_payload_requires_feedback(): void {
        $payload = json_decode($this->valid_payload(), true);
        $payload['feedback'] = '';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Refinement feedback must not be empty');
        refine_course::validate_payload(json_encode($payload));
    }

    public function test_validate_payload_requires_previous_result(): void {
        $payload = json_decode($this->valid_payload(), true);
        $payload['previous_result'] = null;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('previous_result');
        refine_course::validate_payload(json_encode($payload));
    }

    public function test_validate_payload_omits_empty_source_summary(): void {
        $result = refine_course::validate_payload($this->valid_payload());
        $this->assertArrayNotHasKey('source_summary', $result);
    }

    public function test_validate_payload_keeps_source_summary_when_provided(): void {
        $result = refine_course::validate_payload($this->valid_payload([
            'source_summary' => 'Summary of the uploaded syllabus document.',
        ]));

        $this->assertSame('Summary of the uploaded syllabus document.', $result['source_summary']);
    }

    public function test_validate_payload_keeps_sanitized_context(): void {
        $result = refine_course::validate_payload($this->valid_payload([
            'context' => [
                'language' => '  German ',
                'difficulty' => 'beginner',
                'injected' => 'dropped',
            ],
        ]));

        $this->assertSame([
            'difficulty' => 'beginner',
            'language' => 'German',
        ], $result['context']);
    }

    public function test_validate_payload_omits_unusable_context(): void {
        $result = refine_course::validate_payload($this->valid_payload([
            'context' => ['injected' => 'x', 'difficulty' => 42],
        ]));

        $this->assertArrayNotHasKey('context', $result);
    }

    public function test_validate_payload_caps_conversation_at_eight_entries(): void {
        $conversation = [];
        for ($i = 1; $i <= 10; $i++) {
            $role = ($i % 2 === 1) ? 'user' : 'assistant';
            $conversation[] = ['role' => $role, 'content' => 'entry ' . $i];
        }

        $result = refine_course::validate_payload($this->valid_payload([
            'conversation' => $conversation,
        ]));

        $this->assertCount(8, $result['conversation']);
        $this->assertSame('entry 3', $result['conversation'][0]['content']);
        $this->assertSame('entry 10', $result['conversation'][7]['content']);
    }

    public function test_return_path_tolerates_refined_shape_variance(): void {
        // A refined course structure with an EXTRA section, optional keys
        // omitted, and an activity missing its optional description/status.
        // normalize_structure() must coerce it into the declared shape so the
        // encoded preview always parses and clean_returnvalue() never rejects.
        $refinedstructure = [
            'course_name' => 'Intro to Marine Biology',
            'sections' => [
                [
                    'section_name' => 'Week 1',
                    'activities' => [
                        ['activity_name' => 'Welcome', 'moodle_type' => 'page'],
                    ],
                ],
                [
                    // Newly added section — every optional key omitted.
                    'section_name' => 'Coral Reefs',
                ],
            ],
        ];

        $normalized = get_full_analysis::normalize_structure($refinedstructure);
        $result = [
            'success' => true,
            'preview' => json_encode($normalized),
        ];

        $cleaned = \external_api::clean_returnvalue(refine_course::execute_returns(), $result);

        $this->assertTrue($cleaned['success']);
        $decoded = json_decode($cleaned['preview'], true);
        $this->assertCount(2, $decoded['sections']);
        $this->assertSame('Coral Reefs', $decoded['sections'][1]['section_name']);
        // Omitted optional keys are filled so the client renderer never chokes.
        $this->assertSame('NEW', $decoded['sections'][1]['status']);
        $this->assertSame('', $decoded['sections'][1]['description']);
        $this->assertSame([], $decoded['sections'][1]['activities']);
        $this->assertSame('NEW', $decoded['sections'][0]['activities'][0]['status']);
        $this->assertSame('', $decoded['sections'][0]['activities'][0]['description']);
    }

    public function test_execute_returns_error_for_invalid_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = refine_course::execute('not-json{');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid refinement payload JSON', $result['error']);
    }

    public function test_execute_denies_users_without_course_create_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = refine_course::execute($this->valid_payload());
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('Invalid refinement payload', $result['error']);
    }

    public function test_execute_never_creates_a_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $countbefore = $DB->count_records('course');

        // Even with a valid payload the function must never create a course;
        // it only returns a refined structure preview from the dashboard.
        $result = refine_course::execute($this->valid_payload());
        $this->assertDebuggingCalled();

        $this->assertSame($countbefore, $DB->count_records('course'));
        // Without a configured API key the dashboard call fails, but the
        // error envelope is returned instead of a created course.
        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('courseid', $result);
    }
}
