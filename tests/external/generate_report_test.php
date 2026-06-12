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
 * Tests for the generate_report external function.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

/**
 * Tests for the generate_report external function.
 *
 * The dashboard HTTP call itself is not exercised (the api_client has no
 * HTTP mocking layer); the repair orchestration is driven through the
 * callable seam of run_generation(), and validation + execution are covered
 * via process_dashboard_response() against the real test database.
 *
 * @covers \block_mastermind_assistant\external\generate_report
 * @runTestsInSeparateProcesses
 */
final class generate_report_test extends \advanced_testcase {
    /**
     * Create a course with two enrolled students and an editing teacher.
     *
     * @return array [course, teacher, student]
     */
    private function create_environment(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Reporting 101']);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $student = $generator->create_user(['firstname' => 'Ada']);
        $generator->enrol_user($student->id, $course->id, 'student');

        return [$course, $teacher, $student];
    }

    /**
     * A well-formed dashboard response for the test course.
     *
     * @return array
     */
    private function valid_dashboard_response(): array {
        return [
            'success' => true,
            'title' => 'Enrolled students',
            'explanation' => 'All students enrolled in this course.',
            'sql' => 'SELECT u.id, u.firstname
                        FROM {user} u
                        JOIN {user_enrolments} ue ON ue.userid = u.id
                        JOIN {enrol} e ON e.id = ue.enrolid
                       WHERE e.courseid = :courseid
                    ORDER BY u.id ASC',
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'firstname', 'label' => 'First name'],
            ],
        ];
    }

    public function test_execute_denies_students(): void {
        $this->resetAfterTest();
        [$course, , $student] = $this->create_environment();
        $this->setUser($student);

        $result = generate_report::execute($course->id, 'list everything');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_execute_denies_teacher_without_viewreports(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher] = $this->create_environment();

        // The block view capability alone must not be enough: take
        // moodle/site:viewreports away in the course context.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $context = \context_course::instance($course->id);
        assign_capability('moodle/site:viewreports', CAP_PROHIBIT, $roleid, $context->id, true);

        $this->setUser($teacher);
        $result = generate_report::execute($course->id, 'list enrolled students');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
    }

    public function test_execute_rejects_empty_request(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();
        $this->setAdminUser();

        $result = generate_report::execute($course->id, "   \n  ");
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('empty', $result['message']);
    }

    public function test_map_dbtype(): void {
        $this->assertSame('mysql', generate_report::map_dbtype('mysqli'));
        $this->assertSame('mariadb', generate_report::map_dbtype('mariadb'));
        $this->assertSame('pgsql', generate_report::map_dbtype('pgsql'));
        // Anything else falls back to mysql.
        $this->assertSame('mysql', generate_report::map_dbtype('sqlsrv'));
        $this->assertSame('mysql', generate_report::map_dbtype('oci'));
        $this->assertSame('mysql', generate_report::map_dbtype(''));
    }

    public function test_build_payload_is_assembled_server_side(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_environment();

        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'name' => 'Midterm quiz',
        ]);

        $conversation = json_encode([
            ['role' => 'user', 'content' => 'first request'],
            ['role' => 'assistant', 'content' => 'first result'],
            ['role' => 'broken'],
        ]);

        $payload = generate_report::build_payload(
            (int) $course->id,
            'who never attempted the midterm?',
            $conversation,
            'SELECT 1'
        );

        $this->assertSame('who never attempted the midterm?', $payload['request']);
        $this->assertContains($payload['dbtype'], ['mysql', 'mariadb', 'pgsql']);
        $this->assertSame('Reporting 101', $payload['course_name']);
        $this->assertContains('Midterm quiz', $payload['activity_names']);
        $this->assertLessThanOrEqual(generate_report::MAX_ACTIVITY_NAMES, count($payload['activity_names']));
        // Malformed conversation entries are dropped.
        $this->assertCount(2, $payload['conversation']);
        $this->assertSame('SELECT 1', $payload['previous_sql']);
    }

    public function test_build_payload_omits_empty_previous_sql(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();

        $payload = generate_report::build_payload((int) $course->id, 'a report', '[]', '');

        $this->assertArrayNotHasKey('previous_sql', $payload);
        $this->assertSame([], $payload['conversation']);
    }

    public function test_process_dashboard_response_executes_valid_sql(): void {
        $this->resetAfterTest();
        [$course, $teacher, $student] = $this->create_environment();

        $processed = generate_report::process_dashboard_response(
            $this->valid_dashboard_response(),
            (int) $course->id
        );

        $this->assertTrue($processed['ok']);
        $data = $processed['data'];
        $this->assertTrue($data['success']);
        $this->assertSame('Enrolled students', $data['title']);
        $this->assertSame(
            [['key' => 'id', 'label' => 'ID'], ['key' => 'firstname', 'label' => 'First name']],
            $data['columns']
        );
        // Teacher + student are enrolled; rows are stringified cell values.
        $this->assertSame(2, $data['total_rows']);
        $this->assertSame((string) $teacher->id, $data['rows'][0][0]);
        $this->assertSame((string) $student->id, $data['rows'][1][0]);
        $this->assertSame('Ada', $data['rows'][1][1]);
        // The validated SQL is echoed back for the export buttons.
        $this->assertSame($this->valid_dashboard_response()['sql'], $data['sql']);
    }

    public function test_process_dashboard_response_executes_sql_with_repeated_courseid(): void {
        $this->resetAfterTest();
        [$course, $teacher, $student] = $this->create_environment();

        // LLM-generated multi-join reports often filter by course in more
        // than one place. Moodle's DML forbids reusing one named parameter,
        // so execution must rewrite the repeats — while the validator and
        // the echoed export SQL keep the original :courseid statement.
        $response = $this->valid_dashboard_response();
        $response['sql'] = 'SELECT u.id, u.firstname
                              FROM {user} u
                              JOIN {user_enrolments} ue ON ue.userid = u.id
                              JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                              JOIN {course} c ON c.id = :courseid
                             WHERE u.deleted = 0
                          ORDER BY u.id ASC';

        $processed = generate_report::process_dashboard_response($response, (int) $course->id);

        $this->assertTrue($processed['ok'], $processed['error']);
        $this->assertSame(2, $processed['data']['total_rows']);
        $this->assertSame((string) $teacher->id, $processed['data']['rows'][0][0]);
        $this->assertSame('Ada', $processed['data']['rows'][1][1]);
        $this->assertSame((string) $student->id, $processed['data']['rows'][1][0]);
        // The ORIGINAL SQL (plain :courseid) is echoed back for the export
        // buttons — the rewrite is an execution-time detail only.
        $this->assertSame($response['sql'], $processed['data']['sql']);
        $this->assertStringNotContainsString(':courseid0', $processed['data']['sql']);
    }

    public function test_process_dashboard_response_falls_back_to_row_keys(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();

        $response = $this->valid_dashboard_response();
        unset($response['columns']);

        $processed = generate_report::process_dashboard_response($response, (int) $course->id);

        $this->assertTrue($processed['ok']);
        $this->assertSame(
            [['key' => 'id', 'label' => 'id'], ['key' => 'firstname', 'label' => 'firstname']],
            $processed['data']['columns']
        );
    }

    public function test_process_dashboard_response_rejects_invalid_sql(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();

        $response = $this->valid_dashboard_response();
        $response['sql'] = 'SELECT * FROM mdl_user WHERE id = :courseid';

        $processed = generate_report::process_dashboard_response($response, (int) $course->id);

        $this->assertFalse($processed['ok']);
        $this->assertStringContainsString('SQL validation failed', $processed['error']);
        $this->assertNull($processed['data']);
    }

    public function test_process_dashboard_response_reports_execution_errors(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();

        $response = $this->valid_dashboard_response();
        // Passes the validator but references a column that does not exist.
        $response['sql'] = 'SELECT nosuchcolumn FROM {course} WHERE id = :courseid';

        $processed = generate_report::process_dashboard_response($response, (int) $course->id);

        $this->assertFalse($processed['ok']);
        $this->assertStringContainsString('SQL execution failed', $processed['error']);
        $this->assertNull($processed['data']);
    }

    public function test_run_generation_repairs_once_then_succeeds(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();

        $badresponse = $this->valid_dashboard_response();
        $badresponse['sql'] = 'SELECT secret FROM {config}';

        $calls = [];
        $goodresponse = $this->valid_dashboard_response();
        $requester = function (array $payload) use (&$calls, $badresponse, $goodresponse): array {
            $calls[] = $payload;
            return (count($calls) === 1) ? $badresponse : $goodresponse;
        };

        $payload = ['request' => 'enrolled students', 'dbtype' => 'mysql'];
        $result = generate_report::run_generation($requester, $payload, (int) $course->id);

        $this->assertCount(2, $calls);
        // The first call carries no repair hint, the second carries the
        // failed SQL plus the validation error.
        $this->assertArrayNotHasKey('repair', $calls[0]);
        $this->assertSame('SELECT secret FROM {config}', $calls[1]['repair']['sql']);
        $this->assertStringContainsString('SQL validation failed', $calls[1]['repair']['error']);
        // The original payload is preserved on the repair call.
        $this->assertSame('enrolled students', $calls[1]['request']);

        $this->assertTrue($result['success']);
        $this->assertSame('Enrolled students', $result['title']);
        $this->assertSame(2, $result['total_rows']);
    }

    public function test_run_generation_fails_after_single_repair(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();

        $badresponse = $this->valid_dashboard_response();
        $badresponse['sql'] = 'DROP TABLE {user}';

        $callcount = 0;
        $requester = function (array $payload) use (&$callcount, $badresponse): array {
            $callcount++;
            return $badresponse;
        };

        $result = generate_report::run_generation($requester, ['request' => 'x'], (int) $course->id);

        // Exactly one repair attempt — never a third dashboard call.
        $this->assertSame(2, $callcount);
        $this->assertFalse($result['success']);
        $this->assertSame(
            get_string('report_failed', 'block_mastermind_assistant'),
            $result['message']
        );
    }

    public function test_run_generation_repairs_execution_errors_too(): void {
        $this->resetAfterTest();
        [$course] = $this->create_environment();

        // Validator-clean SQL that fails at execution time.
        $badresponse = $this->valid_dashboard_response();
        $badresponse['sql'] = 'SELECT nosuchcolumn FROM {course} WHERE id = :courseid';

        $calls = [];
        $goodresponse = $this->valid_dashboard_response();
        $requester = function (array $payload) use (&$calls, $badresponse, $goodresponse): array {
            $calls[] = $payload;
            return (count($calls) === 1) ? $badresponse : $goodresponse;
        };

        $result = generate_report::run_generation($requester, [], (int) $course->id);

        $this->assertCount(2, $calls);
        $this->assertStringContainsString('SQL execution failed', $calls[1]['repair']['error']);
        $this->assertTrue($result['success']);
    }
}
