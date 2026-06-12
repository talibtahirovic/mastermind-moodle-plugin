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
 * Tests for the grading_assist external function.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Tests for the grading_assist external function.
 *
 * The dashboard HTTP call itself is not exercised here (the api_client has
 * no HTTP mocking layer); these tests cover capability enforcement, the
 * anonymous listing of items awaiting grading, and the server-side payload
 * building including the anonymization guarantee (no student names, user
 * ids or emails ever leave the site).
 *
 * @covers \block_mastermind_assistant\external\grading_assist
 * @runTestsInSeparateProcesses
 */
final class grading_assist_test extends \advanced_testcase {
    /** @var string Distinctive student firstname that must never appear in payloads. */
    const STUDENT_FIRSTNAME = 'Privacyfirstname';

    /** @var string Distinctive student lastname that must never appear in payloads. */
    const STUDENT_LASTNAME = 'Privacylastname';

    /** @var string Distinctive student email that must never appear in payloads. */
    const STUDENT_EMAIL = 'privacy.student@example.com';

    /**
     * Create a course with an assignment (online text enabled) and a student.
     *
     * @param array $assignoptions Extra options for the assign instance.
     * @return array [course, assign instance record, cm, student]
     */
    private function create_assign_environment(array $assignoptions = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $assign = $generator->get_plugin_generator('mod_assign')->create_instance(array_merge([
            'course' => $course->id,
            'name' => 'Climate essay assignment',
            'intro' => '<p>Write 500 words about <strong>climate change</strong>.</p>',
            'grade' => 80,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1048576,
            'submissiondrafts' => 0,
        ], $assignoptions));

        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $student = $generator->create_user([
            'firstname' => self::STUDENT_FIRSTNAME,
            'lastname' => self::STUDENT_LASTNAME,
            'email' => self::STUDENT_EMAIL,
        ]);
        $generator->enrol_user($student->id, $course->id, 'student');

        return [$course, $assign, $cm, $student];
    }

    /**
     * Create an online text submission for a student.
     *
     * @param \stdClass $cm Assignment course module.
     * @param \stdClass $student Student user.
     * @param string $onlinetext Submission HTML.
     */
    private function create_onlinetext_submission(\stdClass $cm, \stdClass $student, string $onlinetext): void {
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $cm->id,
            'onlinetext' => $onlinetext,
        ]);
    }

    /**
     * Create a quiz with one essay question and a finished student attempt.
     *
     * @param string $responsetext The student's essay answer.
     * @return array [cm, attempt record, student]
     */
    private function create_essay_attempt_environment(string $responsetext): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
            'sumgrades' => 10,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $essay = $questiongenerator->create_question('essay', null, ['category' => $cat->id]);
        quiz_add_quiz_question($essay->id, $quiz, 0, 10);

        $student = $generator->create_user([
            'firstname' => self::STUDENT_FIRSTNAME,
            'lastname' => self::STUDENT_LASTNAME,
            'email' => self::STUDENT_EMAIL,
        ]);
        $generator->enrol_user($student->id, $course->id, 'student');

        $quizobj = quiz_settings::create($quiz->id, $student->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, [
            1 => ['answer' => $responsetext, 'answerformat' => FORMAT_HTML],
        ]);
        $attemptobj->process_finish($timenow, false);

        $attemptrecord = $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);

        return [$cm, $attemptrecord, $student];
    }

    /**
     * Assert that a payload/result contains no trace of the student identity.
     *
     * @param array $data Structure that is (or feeds) an outbound payload.
     */
    private function assert_anonymized(array $data): void {
        $json = json_encode($data);
        $this->assertStringNotContainsString(self::STUDENT_FIRSTNAME, $json);
        $this->assertStringNotContainsString(self::STUDENT_LASTNAME, $json);
        $this->assertStringNotContainsString(self::STUDENT_EMAIL, $json);
        $this->assertStringNotContainsString('userid', $json);
    }

    public function test_execute_denies_students(): void {
        $this->resetAfterTest();
        [$course, , $cm, $student] = $this->create_assign_environment();
        $this->setUser($student);

        $result = grading_assist::execute($cm->id, 'assign', 'list');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_execute_denies_teacher_without_grade_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, , $cm] = $this->create_assign_environment();

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Take mod/assign:grade away in the module context: the block view
        // capability alone must not be enough.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $context = \context_module::instance($cm->id);
        assign_capability('mod/assign:grade', CAP_PROHIBIT, $roleid, $context->id, true);

        $this->setUser($teacher);
        $result = grading_assist::execute($cm->id, 'assign', 'list');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
    }

    public function test_execute_admits_grader_for_list(): void {
        $this->resetAfterTest();
        [$course, , $cm, $student] = $this->create_assign_environment();
        $this->create_onlinetext_submission($cm, $student, '<p>My submission text.</p>');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = grading_assist::execute($cm->id, 'assign', 'list');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['items']);
    }

    public function test_list_returns_anonymous_labels_for_ungraded_submissions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $cm, $student1] = $this->create_assign_environment();

        $student2 = $this->getDataGenerator()->create_user([
            'firstname' => self::STUDENT_FIRSTNAME,
            'lastname' => self::STUDENT_LASTNAME,
            'email' => 'privacy.student2@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $this->create_onlinetext_submission($cm, $student1, '<p>First answer.</p>');
        $this->create_onlinetext_submission($cm, $student2, '<p>Second answer.</p>');

        $result = grading_assist::execute($cm->id, 'assign', 'list');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['items']);

        $labels = array_column($result['items'], 'label');
        $this->assertSame(['Submission 1', 'Submission 2'], $labels);
        $this->assertTrue($result['items'][0]['has_text']);

        // Privacy: no student identity in the returned structure.
        $this->assert_anonymized($result['items']);
        $this->assertStringNotContainsString('privacy.student2@example.com', json_encode($result['items']));
    }

    public function test_assign_payload_contains_text_but_no_identity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $assign, $cm, $student] = $this->create_assign_environment();
        $this->create_onlinetext_submission(
            $cm,
            $student,
            '<p>The greenhouse effect traps heat in the atmosphere.</p>'
        );

        $submission = $DB->get_record(
            'assign_submission',
            ['assignment' => $assign->id, 'userid' => $student->id],
            '*',
            MUST_EXIST
        );

        $payload = grading_assist::build_assign_payload($cm->id, (int) $submission->id);

        $this->assertSame('assignment', $payload['type']);
        $this->assertSame('Climate essay assignment', $payload['task']['name']);
        $this->assertStringContainsStringIgnoringCase('climate change', $payload['task']['instructions']);
        $this->assertEqualsWithDelta(80.0, $payload['task']['max_grade'], 0.001);
        $this->assertStringContainsString('greenhouse effect traps heat', $payload['submission_text']);

        // The payload carries exactly the contract keys — nothing extra.
        $this->assertSame(['type', 'task', 'submission_text'], array_keys($payload));
        $this->assertSame(['name', 'instructions', 'rubric', 'max_grade'], array_keys($payload['task']));

        // Privacy: no student name, email or user id anywhere in the payload.
        $this->assert_anonymized($payload);
    }

    public function test_assign_payload_tolerates_empty_intro(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Assignment with no description at all — the payload must still be
        // built (no exception), with instructions as an empty string and the
        // assignment name always present. The generator refuses an empty
        // intro, so it is blanked directly in the database.
        [, $assign, $cm, $student] = $this->create_assign_environment();
        $DB->set_field('assign', 'intro', '', ['id' => $assign->id]);
        $this->create_onlinetext_submission($cm, $student, '<p>An answer without a question.</p>');

        $submission = $DB->get_record(
            'assign_submission',
            ['assignment' => $assign->id, 'userid' => $student->id],
            '*',
            MUST_EXIST
        );

        $payload = grading_assist::build_assign_payload($cm->id, (int) $submission->id);

        $this->assertSame('assignment', $payload['type']);
        $this->assertSame('Climate essay assignment', $payload['task']['name']);
        $this->assertArrayHasKey('instructions', $payload['task']);
        $this->assertSame('', $payload['task']['instructions']);
        $this->assertStringContainsString('An answer without a question.', $payload['submission_text']);
        $this->assert_anonymized($payload);
    }

    public function test_quiz_essay_list_and_payload(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $responsetext = 'Once upon a time a frog learned about data privacy.';
        [$cm, $attempt] = $this->create_essay_attempt_environment($responsetext);

        // The finished essay attempt awaits manual grading.
        $items = grading_assist::list_quiz_items($cm->id);
        $this->assertCount(1, $items);
        $this->assertSame($attempt->id . '-1', $items[0]['id']);
        $this->assertSame('Attempt 1 — Q1', $items[0]['label']);
        $this->assertTrue($items[0]['has_text']);
        $this->assert_anonymized($items);

        // The payload contains the question text and the student's response.
        $payload = grading_assist::build_quiz_payload($cm->id, $attempt->id . '-1');

        $this->assertSame('essay', $payload['type']);
        $this->assertStringContainsString('frog', $payload['task']['instructions']);
        $this->assertStringContainsString($responsetext, $payload['submission_text']);
        $this->assertEqualsWithDelta(10.0, $payload['task']['max_grade'], 0.001);
        $this->assert_anonymized($payload);
    }

    public function test_analyze_with_empty_submission_returns_nothing_to_analyze(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $assign, $cm, $student] = $this->create_assign_environment();

        // Submitted item whose online text ends up empty and that has no files.
        $this->create_onlinetext_submission($cm, $student, '<p>placeholder</p>');

        $submission = $DB->get_record(
            'assign_submission',
            ['assignment' => $assign->id, 'userid' => $student->id],
            '*',
            MUST_EXIST
        );
        $DB->set_field('assignsubmission_onlinetext', 'onlinetext', '', ['submission' => $submission->id]);

        $result = grading_assist::execute($cm->id, 'assign', 'analyze', (string) $submission->id);
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            get_string('grading_error_nothing_to_analyze', 'block_mastermind_assistant'),
            $result['error']
        );
    }

    public function test_execute_rejects_unknown_modtype(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , $cm] = $this->create_assign_environment();

        $result = grading_assist::execute($cm->id, 'forum', 'list');
        $this->assertDebuggingCalled();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported module type', $result['error']);
    }
}
