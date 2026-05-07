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
 * External function to generate quiz questions
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_course;
use context_module;
use Exception;

/**
 * External API for generate quiz questions.
 */
class generate_quiz_questions extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'quizid' => new external_value(PARAM_INT, 'Quiz CM ID'),
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
            'quizdescription' => new external_value(PARAM_RAW, 'Quiz description', VALUE_DEFAULT, ''),
            'difficultylevel' => new external_value(PARAM_TEXT, 'Difficulty level', VALUE_DEFAULT, ''),
            'questioncount' => new external_value(PARAM_INT, 'Number of questions to generate', VALUE_DEFAULT, 8),
            'academiclevel' => new external_value(PARAM_TEXT, 'Academic level', VALUE_DEFAULT, ''),
            'sectionname' => new external_value(PARAM_TEXT, 'Section name for context', VALUE_DEFAULT, ''),
            'courseactivities' => new external_value(PARAM_RAW, 'JSON array of course activity names', VALUE_DEFAULT, '[]'),
            'previewonly' => new external_value(PARAM_BOOL, 'If true, return questions without inserting', VALUE_DEFAULT, true),
            'selectedquestions' => new external_value(PARAM_RAW, 'JSON array of selected questions to insert', VALUE_DEFAULT, ''),
            'filedata' => new external_value(PARAM_RAW, 'Base64-encoded source document', VALUE_DEFAULT, ''),
            'filetype' => new external_value(PARAM_TEXT, 'MIME type of source document', VALUE_DEFAULT, ''),
            'filename' => new external_value(PARAM_TEXT, 'Original file name', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the web service call.
     *
     * @param int $courseid Course ID.
     * @param int $quizid Quiz CM ID.
     * @param string $quizname Quiz name.
     * @param string $quizdescription Quiz description.
     * @param string $difficultylevel Difficulty level.
     * @param int $questioncount Number of questions to generate.
     * @param string $academiclevel Academic level.
     * @param string $sectionname Section name for context.
     * @param string $courseactivities JSON array of course activity names.
     * @param bool $previewonly If true, return questions without inserting.
     * @param string $selectedquestions JSON array of selected questions to insert.
     * @param string $filedata Base64-encoded source document.
     * @param string $filetype MIME type of source document.
     * @param string $filename Original file name.
     * @return array
     */
    public static function execute(
        $courseid,
        $quizid,
        $quizname,
        $quizdescription,
        $difficultylevel = '',
        $questioncount = 8,
        $academiclevel = '',
        $sectionname = '',
        $courseactivities = '[]',
        $previewonly = true,
        $selectedquestions = '',
        $filedata = '',
        $filetype = '',
        $filename = ''
    ) {
        global $DB;

        @set_time_limit(600);

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'quizid' => $quizid,
            'quizname' => $quizname,
            'quizdescription' => $quizdescription,
            'difficultylevel' => $difficultylevel,
            'questioncount' => $questioncount,
            'academiclevel' => $academiclevel,
            'sectionname' => $sectionname,
            'courseactivities' => $courseactivities,
            'previewonly' => $previewonly,
            'selectedquestions' => $selectedquestions,
            'filedata' => $filedata,
            'filetype' => $filetype,
            'filename' => $filename,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        // AI generation can take time for complex content.
        \core_php_time_limit::raise(300);

        if (empty($params['quizid'])) {
            throw new Exception('Please save the quiz first before generating questions.');
        }

        $cm = get_coursemodule_from_id('quiz', $params['quizid'], 0, false, IGNORE_MISSING);
        if (!$cm) {
            throw new Exception('Quiz not found. Please save the quiz first.');
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        try {
            // Phase 2: Insert selected questions from preview.
            if (!empty($params['selectedquestions'])) {
                $questions = json_decode($params['selectedquestions'], true);
                if (empty($questions) || !is_array($questions)) {
                    throw new Exception('Invalid selected questions data.');
                }

                $createdquestions = self::create_moodle_questions($questions, $quiz, $cm, $context);
                $newcount = count($createdquestions);

                return [
                    'success' => true,
                    'questioncount' => $newcount,
                    'questions' => json_encode($createdquestions),
                    'message' => $newcount . ' question' . ($newcount != 1 ? 's' : '') . ' added successfully',
                ];
            }

            // Phase 1: Generate questions via AI.
            $existingquestions = self::get_existing_quiz_questions($quiz);

            $client = new \block_mastermind_assistant\api_client();

            if (!empty($params['filedata'])) {
                // Source-document path: validate file first, then route to the
                // document-aware endpoint. Skips course-activity context since
                // the document itself is the authoritative source.
                $allowedtypes = [
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'text/plain',
                ];
                if (!in_array($params['filetype'], $allowedtypes)) {
                    throw new Exception('Unsupported file type. Please upload a PDF, DOCX, or TXT file.');
                }
                $rawdata = base64_decode($params['filedata'], true);
                if ($rawdata === false) {
                    throw new Exception('Invalid file data.');
                }
                $rawsize = strlen($rawdata);
                if ($rawsize > 10 * 1024 * 1024) {
                    throw new Exception('File exceeds maximum size of 10MB.');
                }
                if ($rawsize < 100) {
                    throw new Exception('File appears to be empty or too small.');
                }

                $response = $client->generate_quiz_from_document(
                    $params['quizname'],
                    $params['quizdescription'],
                    $params['filedata'],
                    $params['filetype'],
                    $params['filename'],
                    $existingquestions,
                    $params['difficultylevel'],
                    $params['questioncount'],
                    $params['academiclevel']
                );
            } else {
                // Gather course activities for context if not provided.
                $activities = json_decode($params['courseactivities'], true) ?: [];
                if (empty($activities)) {
                    $modinfo = get_fast_modinfo($params['courseid']);
                    foreach ($modinfo->get_cms() as $cminfo) {
                        if ($cminfo->visible && $cminfo->has_view()) {
                            $activities[] = $cminfo->name . ' (' . $cminfo->modname . ')';
                        }
                    }
                }

                $response = $client->generate_quiz(
                    $params['quizname'],
                    $params['quizdescription'],
                    $existingquestions,
                    $params['difficultylevel'],
                    $params['questioncount'],
                    $params['academiclevel'],
                    $params['sectionname'],
                    $activities
                );
            }

            $questions = $response['questions'] ?? [];

            if ($params['previewonly']) {
                // Return questions for preview without inserting.
                return [
                    'success' => true,
                    'questioncount' => count($questions),
                    'questions' => json_encode($questions),
                    'message' => count($questions) . ' questions generated for review',
                ];
            }

            // Direct insert (backward compat).
            $createdquestions = self::create_moodle_questions($questions, $quiz, $cm, $context);
            $newcount = count($createdquestions);

            return [
                'success' => true,
                'questioncount' => $newcount,
                'questions' => json_encode($createdquestions),
                'message' => $newcount . ' question' . ($newcount != 1 ? 's' : '') . ' added successfully',
            ];
        } catch (Exception $e) {
            debugging("generate_quiz_questions error: " . $e->getMessage());
            return [
                'success' => false,
                'questioncount' => 0,
                'questions' => json_encode([]),
                'message' => 'Error generating questions: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get existing quiz questions.
     *
     * @param object $quiz Quiz record.
     * @return array List of existing question summaries.
     */
    protected static function get_existing_quiz_questions($quiz) {
        global $DB, $USER, $CFG;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $existingquestions = [];

        try {
            // Moodle 4.5+ moved the legacy `quiz` class to `mod_quiz\quiz_settings`.
            // The new class has the same `create($quizid, $userid)` signature and
            // has been available since 4.2, so it covers our entire 4.4–5.2 matrix.
            $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $USER->id);
            $structure = $quizobj->get_structure();
            $slots = $structure->get_slots();

            if (empty($slots)) {
                return [];
            }

            foreach ($slots as $slotid => $slotobject) {
                $slotnumber = is_object($slotobject) && isset($slotobject->slot) ? $slotobject->slot : $slotid;
                $questiondata = $structure->get_question_in_slot($slotnumber);

                if (!$questiondata) {
                    continue;
                }

                $existingquestions[] = [
                    'name' => $questiondata->name,
                    'text' => strip_tags($questiondata->questiontext),
                    'type' => $questiondata->qtype,
                ];
            }
        } catch (Exception $e) {
            debugging("Error retrieving existing questions: " . $e->getMessage());
        }

        return $existingquestions;
    }

    /**
     * Create moodle questions.
     *
     * @param array $questions AI-generated questions.
     * @param object $quiz Quiz record.
     * @param object $cm Course module record.
     * @param \context $context Module context.
     * @return int Number of questions created.
     */
    protected static function create_moodle_questions($questions, $quiz, $cm, $context) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/question/engine/bank.php');

        $createdquestions = [];

        $modulecontext = context_module::instance($cm->id);
        $categories = $DB->get_records('question_categories', [
            'contextid' => $modulecontext->id,
        ], 'id DESC', '*', 0, 1);

        if (empty($categories)) {
            $categorydata = new \stdClass();
            $categorydata->name = 'AI Generated for ' . $quiz->name;
            $categorydata->contextid = $modulecontext->id;
            $categorydata->info = 'Questions generated by Mastermind Assistant';
            $categorydata->infoformat = FORMAT_HTML;
            $categorydata->stamp = make_unique_id_code();
            $categorydata->parent = question_get_top_category($modulecontext->id, true)->id;
            $categorydata->sortorder = 999;
            $categorydata->id = $DB->insert_record('question_categories', $categorydata);
            $category = $categorydata;
        } else {
            $category = reset($categories);
        }

        foreach ($questions as $qdata) {
            try {
                $questionid = self::create_single_question($qdata, $category->id, $modulecontext);

                if ($questionid) {
                    $maxmark = $qdata['defaultmark'] ?? 1.0;
                    quiz_add_quiz_question($questionid, $quiz, 0, $maxmark);

                    $createdquestions[] = [
                        'id' => $questionid,
                        'name' => $qdata['name'] ?? substr($qdata['questiontext'], 0, 50),
                        'type' => $qdata['type'],
                    ];
                }
            } catch (Exception $e) {
                debugging("Error creating question: " . $e->getMessage());
            }
        }

        // Recompute quiz grades.
        $quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        $gradecalculator = $quizobj->get_grade_calculator();
        $gradecalculator->recompute_quiz_sumgrades();

        $quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        quiz_grade_item_update($quiz);
        quiz_delete_previews($quiz);

        return $createdquestions;
    }

    /**
     * Create single question.
     *
     * @param array $qdata Question data from the AI.
     * @param int $categoryid Question category ID.
     * @param \context $modulecontext Module context.
     * @return int|false Question ID on success, false on failure.
     */
    protected static function create_single_question($qdata, $categoryid, $modulecontext) {
        global $USER;

        $question = new \stdClass();
        $question->category = $categoryid;
        $question->contextid = $modulecontext->id;
        $question->parent = 0;
        $question->name = $qdata['name'] ?? substr(strip_tags($qdata['questiontext']), 0, 50);
        $question->questiontext = $qdata['questiontext'];
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = $qdata['generalfeedback'] ?? '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = $qdata['defaultmark'] ?? 1.0;
        $question->penalty = 0.3333333;
        $question->qtype = $qdata['type'];
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->version = make_unique_id_code();
        $question->hidden = 0;
        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;

        $fromform = new \stdClass();
        $fromform->category = $categoryid;
        $fromform->name = $question->name;
        $fromform->questiontext = ['text' => $qdata['questiontext'], 'format' => FORMAT_HTML];
        $fromform->generalfeedback = ['text' => $qdata['generalfeedback'] ?? '', 'format' => FORMAT_HTML];
        $fromform->defaultmark = $qdata['defaultmark'] ?? 1.0;
        $fromform->penalty = 0.3333333;
        $fromform->qtype = $qdata['type'];

        if ($qdata['type'] === 'multichoice') {
            $fromform->single = 1;
            $fromform->shuffleanswers = 1;
            $fromform->answernumbering = 'abc';
            $fromform->correctfeedback = ['text' => 'Your answer is correct.', 'format' => FORMAT_HTML];
            $fromform->partiallycorrectfeedback = ['text' => 'Your answer is partially correct.', 'format' => FORMAT_HTML];
            $fromform->incorrectfeedback = ['text' => 'Your answer is incorrect.', 'format' => FORMAT_HTML];

            $fromform->answer = [];
            $fromform->fraction = [];
            $fromform->feedback = [];

            foreach ($qdata['answers'] as $ans) {
                $fromform->answer[] = ['text' => $ans['answer'], 'format' => FORMAT_HTML];
                $fromform->fraction[] = $ans['fraction'];
                $fromform->feedback[] = ['text' => $ans['feedback'] ?? '', 'format' => FORMAT_HTML];
            }
        } else if ($qdata['type'] === 'truefalse') {
            $trueanswer = null;
            $falseanswer = null;

            foreach ($qdata['answers'] as $ans) {
                if (stripos($ans['answer'], 'true') !== false) {
                    $trueanswer = $ans;
                } else {
                    $falseanswer = $ans;
                }
            }

            $fromform->correctanswer = ($trueanswer && $trueanswer['fraction'] > 0) ? 1 : 0;
            $fromform->feedbacktrue = ['text' => $trueanswer['feedback'] ?? 'Correct!', 'format' => FORMAT_HTML];
            $fromform->feedbackfalse = ['text' => $falseanswer['feedback'] ?? 'Incorrect!', 'format' => FORMAT_HTML];
        } else if ($qdata['type'] === 'shortanswer') {
            $fromform->usecase = 0;
            $fromform->answer = [];
            $fromform->fraction = [];
            $fromform->feedback = [];

            foreach ($qdata['answers'] as $ans) {
                $fromform->answer[] = $ans['answer'];
                $fromform->fraction[] = $ans['fraction'];
                $fromform->feedback[] = ['text' => $ans['feedback'] ?? '', 'format' => FORMAT_HTML];
            }
        } else if ($qdata['type'] === 'match') {
            $fromform->shuffleanswers = 1;
            $fromform->correctfeedback = ['text' => 'Your answer is correct.', 'format' => FORMAT_HTML];
            $fromform->partiallycorrectfeedback = ['text' => 'Your answer is partially correct.', 'format' => FORMAT_HTML];
            $fromform->incorrectfeedback = ['text' => 'Your answer is incorrect.', 'format' => FORMAT_HTML];

            $fromform->subquestions = [];
            $fromform->subanswers = [];

            $subquestions = $qdata['subquestions'] ?? [];
            foreach ($subquestions as $sq) {
                $fromform->subquestions[] = ['text' => $sq['question'], 'format' => FORMAT_HTML];
                $fromform->subanswers[] = $sq['answer'];
            }
        } else if ($qdata['type'] === 'gapselect') {
            $fromform->shuffleanswers = 1;
            $fromform->correctfeedback = ['text' => 'Your answer is correct.', 'format' => FORMAT_HTML];
            $fromform->partiallycorrectfeedback = ['text' => 'Your answer is partially correct.', 'format' => FORMAT_HTML];
            $fromform->incorrectfeedback = ['text' => 'Your answer is incorrect.', 'format' => FORMAT_HTML];

            $fromform->choices = [];
            $choices = $qdata['choices'] ?? [];
            foreach ($choices as $choice) {
                $fromform->choices[] = [
                    'answer' => $choice['answer'],
                    'choicegroup' => $choice['choicegroup'],
                ];
            }
        } else if ($qdata['type'] === 'numerical') {
            $fromform->answer = [];
            $fromform->fraction = [];
            $fromform->feedback = [];
            $fromform->tolerance = [];

            foreach ($qdata['answers'] as $ans) {
                $fromform->answer[] = $ans['answer'];
                $fromform->fraction[] = $ans['fraction'];
                $fromform->feedback[] = ['text' => $ans['feedback'] ?? '', 'format' => FORMAT_HTML];
                $fromform->tolerance[] = $ans['tolerance'] ?? 0;
            }

            // Unit handling — no units by default.
            $fromform->unitrole = 0;
            $fromform->unitpenalty = 0.1;
            $fromform->unitgradingtypes = 0;
            $fromform->unitsleft = 0;
            $fromform->nounits = 0;
            $fromform->multiplier = [];
            $fromform->unit = [];
        }

        $qtype = \question_bank::get_qtype($qdata['type']);
        $savedquestion = $qtype->save_question($question, $fromform);

        return $savedquestion->id;
    }

    /**
     * Describe the return value of execute().
     *
     * @return \external_description
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation was successful'),
            'questioncount' => new external_value(PARAM_INT, 'Number of questions'),
            'questions' => new external_value(PARAM_RAW, 'JSON array of questions'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
