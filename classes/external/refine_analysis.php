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
 * External function to refine a previous full analysis with user feedback
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
use external_single_structure;
use external_value;
use context_course;
use Exception;

/**
 * Conversational refinement of a previously generated full analysis.
 *
 * Sends the previous result plus user feedback (and a short conversation
 * history) to the dashboard /api/ma/refine-analysis endpoint and returns
 * a response in the same shape as get_full_analysis. Nothing is applied
 * to the course — that still happens via apply_course_structure.
 */
class refine_analysis extends external_api {
    /** @var int Maximum number of conversation entries forwarded to the dashboard. */
    public const MAX_CONVERSATION_ENTRIES = 8;

    /**
     * Describe the parameters accepted by execute().
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'payload' => new external_value(PARAM_RAW, 'Refinement payload JSON'),
        ]);
    }

    /**
     * Execute the web service call.
     *
     * @param int $courseid Course ID.
     * @param string $payload Refinement payload JSON.
     * @return array
     */
    public static function execute($courseid, $payload) {
        \core_php_time_limit::raise(600);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
                'payload' => $payload,
            ]);

            // Fix potential section ID vs course ID confusion.
            global $DB;
            if (!$DB->record_exists('course', ['id' => $params['courseid']])) {
                $section = $DB->get_record('course_sections', ['id' => $params['courseid']], 'id, course');
                if ($section) {
                    $params['courseid'] = $section->course;
                }
            }

            $context = context_course::instance($params['courseid']);
            self::validate_context($context);
            require_capability('block/mastermind_assistant:view', $context);

            $data = self::validate_payload($params['payload']);

            $client = new \block_mastermind_assistant\api_client();
            $airesponse = $client->refine_analysis($data);

            return [
                'success' => true,
                'recommendations' => get_full_analysis::extract_recommendations($airesponse),
                'structure' => get_full_analysis::extract_structure($airesponse),
            ];
        } catch (Exception $e) {
            debugging('refine_analysis error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'error' => 'AI Refinement Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Decode and validate the refinement payload JSON.
     *
     * @param string $payloadjson Raw payload JSON from the client.
     * @return array Normalized payload ready to forward to the dashboard.
     * @throws Exception When the payload is malformed.
     */
    public static function validate_payload(string $payloadjson): array {
        $data = json_decode($payloadjson, true);
        if (!is_array($data)) {
            throw new Exception('Invalid refinement payload JSON');
        }

        $feedback = isset($data['feedback']) && is_string($data['feedback']) ? trim($data['feedback']) : '';
        if ($feedback === '') {
            throw new Exception('Refinement feedback must not be empty');
        }

        if (empty($data['previous_result']) || !is_array($data['previous_result'])) {
            throw new Exception('Refinement payload requires a previous_result object');
        }

        return [
            'course' => is_array($data['course'] ?? null) ? $data['course'] : [],
            'sections' => is_array($data['sections'] ?? null) ? $data['sections'] : [],
            'activities' => is_array($data['activities'] ?? null) ? $data['activities'] : [],
            'previous_result' => $data['previous_result'],
            'conversation' => self::normalize_conversation($data['conversation'] ?? []),
            'feedback' => $feedback,
        ];
    }

    /**
     * Keep only well-formed {role, content} entries, capped at the most recent
     * MAX_CONVERSATION_ENTRIES items.
     *
     * @param mixed $conversation Raw conversation value from the payload.
     * @return array Normalized conversation entries.
     */
    public static function normalize_conversation($conversation): array {
        if (!is_array($conversation)) {
            return [];
        }

        $entries = [];
        foreach ($conversation as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = $entry['role'] ?? '';
            $content = $entry['content'] ?? '';
            if (!is_string($role) || !is_string($content) || $role === '' || $content === '') {
                continue;
            }
            $entries[] = ['role' => $role, 'content' => $content];
        }

        // Drop oldest entries beyond the cap.
        return array_values(array_slice($entries, -self::MAX_CONVERSATION_ENTRIES));
    }

    /**
     * Describe the return value of execute().
     *
     * @return \external_description
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'recommendations' => new external_value(PARAM_RAW, 'Refined AI recommendations', VALUE_OPTIONAL),
            'structure' => new external_value(PARAM_RAW, 'Refined course structure', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
