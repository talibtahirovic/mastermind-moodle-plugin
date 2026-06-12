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
 * External function for the AI grading assistant.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_module;
use Exception;

/**
 * Grading assistant: list items awaiting manual grading and analyze one item.
 *
 * Two actions share one external function:
 * - 'list'    returns the submissions / question attempts awaiting grading,
 *             labelled with anonymous ordinals (never student names).
 * - 'analyze' builds the dashboard payload entirely server-side (submission
 *             text or file is read from the database / file storage, never
 *             trusted from the client) and forwards it to the dashboard
 *             /api/ma/grading-assist endpoint.
 *
 * Privacy: outbound payloads contain no student names, user ids or emails.
 */
class grading_assist extends external_api {
    /** @var int Maximum submission file size forwarded to the dashboard (10MB). */
    public const MAX_FILE_SIZE = 10485760;

    /** @var string[] File MIME types the dashboard can extract text from. */
    public const SUPPORTED_MIMETYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
    ];

    /**
     * Describe the parameters accepted by execute().
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the assignment or quiz'),
            'modtype' => new external_value(PARAM_ALPHA, 'Module type: assign or quiz'),
            'action' => new external_value(PARAM_ALPHA, 'Action: list or analyze'),
            'itemid' => new external_value(
                PARAM_ALPHANUMEXT,
                'Item to analyze (submission id or attemptid-slot)',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Execute the web service call.
     *
     * @param int $cmid Course module ID.
     * @param string $modtype 'assign' or 'quiz'.
     * @param string $action 'list' or 'analyze'.
     * @param string $itemid Item identifier (analyze only).
     * @return array
     */
    public static function execute($cmid, $modtype, $action, $itemid = '') {
        \core_php_time_limit::raise(600);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'cmid' => $cmid,
                'modtype' => $modtype,
                'action' => $action,
                'itemid' => $itemid,
            ]);

            $context = context_module::instance($params['cmid']);
            self::validate_context($context);
            require_capability('block/mastermind_assistant:view', $context);

            if ($params['modtype'] === 'assign') {
                require_capability('mod/assign:grade', $context);
            } else if ($params['modtype'] === 'quiz') {
                require_capability('mod/quiz:grade', $context);
            } else {
                throw new Exception('Unsupported module type: ' . $params['modtype']);
            }

            if ($params['action'] === 'list') {
                $items = ($params['modtype'] === 'assign')
                    ? self::list_assign_items($params['cmid'])
                    : self::list_quiz_items($params['cmid']);
                return ['success' => true, 'items' => $items];
            }

            if ($params['action'] === 'analyze') {
                $payload = ($params['modtype'] === 'assign')
                    ? self::build_assign_payload($params['cmid'], (int) $params['itemid'])
                    : self::build_quiz_payload($params['cmid'], $params['itemid']);

                $client = new \block_mastermind_assistant\api_client();
                $result = $client->grading_assist($payload);

                return [
                    'success' => true,
                    'analysis' => json_encode($result),
                    'maxgrade' => (float) $payload['task']['max_grade'],
                ];
            }

            throw new Exception('Unsupported action: ' . $params['action']);
        } catch (Exception $e) {
            debugging('grading_assist error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List assignment submissions awaiting grading with anonymous labels.
     *
     * @param int $cmid Course module ID of the assignment.
     * @return array Items: id, label, has_text, has_file, filename.
     */
    public static function list_assign_items(int $cmid): array {
        global $DB;

        [, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        $context = context_module::instance($cmid);

        $items = [];
        $ordinal = 0;
        foreach (self::get_ungraded_submissions((int) $cm->instance) as $submission) {
            $ordinal++;

            $onlinetext = $DB->get_field(
                'assignsubmission_onlinetext',
                'onlinetext',
                ['submission' => $submission->id]
            );
            $hastext = (trim(self::clean_html((string) $onlinetext)) !== '');

            $file = self::find_supported_file($context, (int) $submission->id);

            $item = [
                'id' => (string) $submission->id,
                'label' => get_string('grading_item_submission', 'block_mastermind_assistant', $ordinal),
                'has_text' => $hastext,
                'has_file' => ($file !== null),
            ];
            if ($file !== null) {
                $item['filename'] = $file->get_filename();
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * List quiz question attempts awaiting manual grading with anonymous labels.
     *
     * @param int $cmid Course module ID of the quiz.
     * @return array Items: id (attemptid-slot), label, question_name, has_text, has_file.
     */
    public static function list_quiz_items(int $cmid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        [, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');

        $attempts = $DB->get_records(
            'quiz_attempts',
            ['quiz' => $cm->instance, 'state' => 'finished'],
            'id ASC'
        );

        $items = [];
        $ordinal = 0;
        foreach ($attempts as $attempt) {
            $ordinal++;
            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            foreach ($quba->get_slots() as $slot) {
                $qa = $quba->get_question_attempt($slot);
                if ($qa->get_state() !== \question_state::$needsgrading) {
                    continue;
                }
                $items[] = [
                    'id' => $attempt->id . '-' . $slot,
                    'label' => get_string(
                        'grading_item_attempt',
                        'block_mastermind_assistant',
                        (object) ['attempt' => $ordinal, 'slot' => $slot]
                    ),
                    'question_name' => format_string($qa->get_question(false)->name),
                    'has_text' => (trim((string) $qa->get_response_summary()) !== ''),
                    'has_file' => false,
                ];
            }
        }

        return $items;
    }

    /**
     * Build the anonymized dashboard payload for one assignment submission.
     *
     * Content priority: online text first, then the first supported file
     * (PDF/DOCX/TXT up to 10MB) sent base64-encoded. The payload never
     * contains student names, user ids or emails.
     *
     * The task block always carries the assignment name, and 'instructions'
     * is always present — as an empty string when the assignment has no
     * description (the dashboard accepts empty instructions), exactly like
     * 'rubric' when no advanced grading form is defined.
     *
     * @param int $cmid Course module ID of the assignment.
     * @param int $submissionid Submission ID (from the 'list' action).
     * @return array Payload for api_client::grading_assist().
     * @throws Exception When there is nothing the dashboard can analyze.
     */
    public static function build_assign_payload(int $cmid, int $submissionid): array {
        global $DB;

        [, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        $context = context_module::instance($cmid);

        $instance = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = $DB->get_record(
            'assign_submission',
            ['id' => $submissionid, 'assignment' => $instance->id],
            '*',
            MUST_EXIST
        );

        $payload = [
            'type' => 'assignment',
            'task' => [
                'name' => format_string($instance->name),
                'instructions' => trim(self::clean_html((string) $instance->intro)),
                'rubric' => self::get_rubric_text($context),
                'max_grade' => ($instance->grade > 0) ? (float) $instance->grade : 100.0,
            ],
        ];

        // 1. Online text submission.
        $onlinetext = $DB->get_field(
            'assignsubmission_onlinetext',
            'onlinetext',
            ['submission' => $submission->id]
        );
        $text = trim(self::clean_html((string) $onlinetext));
        if ($text !== '') {
            $payload['submission_text'] = $text;
            return $payload;
        }

        // 2. File submission: first supported file (PDF/DOCX/TXT, <= 10MB).
        $file = self::find_supported_file($context, (int) $submission->id);
        if ($file !== null) {
            $payload['file_data'] = base64_encode($file->get_content());
            $payload['file_type'] = $file->get_mimetype();
            $payload['file_name'] = $file->get_filename();
            return $payload;
        }

        // Files exist but none is a supported type/size.
        $fs = get_file_storage();
        $allfiles = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'id',
            false
        );
        if (!empty($allfiles)) {
            throw new Exception(get_string('grading_error_unsupported_file', 'block_mastermind_assistant'));
        }

        throw new Exception(get_string('grading_error_nothing_to_analyze', 'block_mastermind_assistant'));
    }

    /**
     * Build the anonymized dashboard payload for one quiz essay question attempt.
     *
     * The task's rubric field carries the question's "Information for graders"
     * (graderinfo) when present, and is sent as an empty string when missing —
     * like 'instructions' for a question without text. The payload never
     * contains student names, user ids or emails.
     *
     * @param int $cmid Course module ID of the quiz.
     * @param string $itemid Composite "attemptid-slot" identifier.
     * @return array Payload for api_client::grading_assist().
     * @throws Exception When the item id is malformed or the response is empty.
     */
    public static function build_quiz_payload(int $cmid, string $itemid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        [, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');

        if (!preg_match('/^(\d+)-(\d+)$/', $itemid, $matches)) {
            throw new Exception('Invalid item identifier');
        }
        $attemptid = (int) $matches[1];
        $slot = (int) $matches[2];

        $attempt = $DB->get_record(
            'quiz_attempts',
            ['id' => $attemptid, 'quiz' => $cm->instance],
            '*',
            MUST_EXIST
        );

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $qa = $quba->get_question_attempt($slot);
        $question = $qa->get_question(false);

        $response = trim((string) $qa->get_response_summary());
        if ($response === '') {
            throw new Exception(get_string('grading_error_nothing_to_analyze', 'block_mastermind_assistant'));
        }

        $graderinfo = '';
        if (!empty($question->graderinfo)) {
            $graderinfo = trim(self::clean_html((string) $question->graderinfo));
        }

        return [
            'type' => 'essay',
            'task' => [
                'name' => format_string($question->name),
                'instructions' => trim(self::clean_html((string) $question->questiontext)),
                'rubric' => $graderinfo,
                'max_grade' => (float) $qa->get_max_mark(),
            ],
            'submission_text' => $response,
        ];
    }

    /**
     * Plain-text rendition of the assignment's advanced grading form, if any.
     *
     * Rubric: criterion descriptions with each level's definition and score.
     * Marking guide: criterion shortnames, descriptions and max scores.
     * Other / no advanced grading: empty string.
     *
     * @param \context_module $context Assignment module context.
     * @return string
     */
    public static function get_rubric_text(\context_module $context): string {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $manager = get_grading_manager($context, 'mod_assign', 'submissions');
        $method = $manager->get_active_method();
        if (empty($method)) {
            return '';
        }

        $controller = $manager->get_controller($method);
        if (!$controller->is_form_defined()) {
            return '';
        }
        $definition = $controller->get_definition();

        $lines = [];
        if ($method === 'rubric' && !empty($definition->rubric_criteria)) {
            foreach ($definition->rubric_criteria as $criterion) {
                $lines[] = '- ' . trim(self::clean_html((string) $criterion['description']));
                foreach ($criterion['levels'] ?? [] as $level) {
                    $lines[] = '  * ' . trim(self::clean_html((string) $level['definition']))
                        . ' (' . (float) $level['score'] . ' points)';
                }
            }
        } else if ($method === 'guide' && !empty($definition->guide_criteria)) {
            foreach ($definition->guide_criteria as $criterion) {
                $lines[] = '- ' . trim((string) $criterion['shortname'])
                    . ' (max ' . (float) $criterion['maxscore'] . '): '
                    . trim(self::clean_html((string) $criterion['description']));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Strip HTML markup down to plain text.
     *
     * @param string $html HTML fragment.
     * @return string
     */
    public static function clean_html(string $html): string {
        if ($html === '') {
            return '';
        }
        return html_to_text($html, 0, false);
    }

    /**
     * Find the first supported submitted file (PDF/DOCX/TXT, <= 10MB).
     *
     * @param \context $context Assignment module context.
     * @param int $submissionid Submission ID (file area item id).
     * @return \stored_file|null
     */
    protected static function find_supported_file(\context $context, int $submissionid): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submissionid,
            'id',
            false
        );

        foreach ($files as $file) {
            if ($file->get_filesize() > self::MAX_FILE_SIZE) {
                continue;
            }
            if (in_array($file->get_mimetype(), self::SUPPORTED_MIMETYPES, true)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Ungraded latest submissions for an assignment, oldest first.
     *
     * A submission needs grading when it is submitted and either has no
     * grade record yet or the recorded grade is empty (-1).
     *
     * @param int $assignid Assignment instance ID.
     * @return array assign_submission records keyed by id.
     */
    public static function get_ungraded_submissions(int $assignid): array {
        global $DB;

        $sql = "SELECT s.*
                  FROM {assign_submission} s
             LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                       AND g.userid = s.userid
                       AND g.attemptnumber = s.attemptnumber
                 WHERE s.assignment = :assignid
                       AND s.status = :status
                       AND s.latest = 1
                       AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0)
              ORDER BY s.timemodified ASC, s.id ASC";

        return $DB->get_records_sql($sql, ['assignid' => $assignid, 'status' => 'submitted']);
    }

    /**
     * Describe the return value of execute().
     *
     * @return \external_description
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_ALPHANUMEXT, 'Item identifier'),
                    'label' => new external_value(PARAM_TEXT, 'Anonymous item label'),
                    'has_text' => new external_value(PARAM_BOOL, 'Whether the item has analyzable text'),
                    'has_file' => new external_value(PARAM_BOOL, 'Whether the item has a supported file'),
                    'filename' => new external_value(PARAM_TEXT, 'Supported file name', VALUE_OPTIONAL),
                    'question_name' => new external_value(PARAM_TEXT, 'Question name (quiz only)', VALUE_OPTIONAL),
                ]),
                'Items awaiting grading',
                VALUE_OPTIONAL
            ),
            'analysis' => new external_value(PARAM_RAW, 'Dashboard analysis result JSON', VALUE_OPTIONAL),
            'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade for the analyzed task', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
