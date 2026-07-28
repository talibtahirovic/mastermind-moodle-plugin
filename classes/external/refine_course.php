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
 * External function to refine a previewed AI course structure with user feedback
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
use context_system;
use Exception;

/**
 * Conversational refinement of a previewed AI-generated course structure.
 *
 * Sends the previous structure plus user feedback (and a short conversation
 * history) to the dashboard /api/ma/refine-course endpoint. This function
 * NEVER creates a course — it only returns the refined structure preview.
 * Creation still happens via create_course_from_structure.
 */
class refine_course extends external_api {
    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'payload' => new external_value(PARAM_RAW, 'Refinement payload JSON'),
        ]);
    }

    /**
     * Refine a previewed course structure via the Dashboard API.
     *
     * @param string $payload Refinement payload JSON.
     * @return array
     */
    public static function execute($payload) {
        \core_php_time_limit::raise(300);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'payload' => $payload,
            ]);

            $context = context_system::instance();
            self::validate_context($context);
            require_capability('moodle/course:create', $context);

            $data = self::validate_payload($params['payload']);

            $client = new \block_mastermind_assistant\api_client();
            $refinedstructure = $client->refine_course($data);

            // Coerce the refined structure into the exact shape the client
            // preview renderer expects before encoding it. AI shape variance
            // (an added section, a missing optional key, a string where a list
            // is expected) would otherwise leave the client unable to parse or
            // render the refined preview ("response could not be handled").
            $preview = is_array($refinedstructure) && isset($refinedstructure['sections'])
                ? get_full_analysis::normalize_structure($refinedstructure)
                : $refinedstructure;

            // Preview only — the course is never created here.
            return [
                'success' => true,
                'preview' => json_encode($preview),
            ];
        } catch (Exception $e) {
            debugging('refine_course error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'error' => $e->getMessage(),
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

        $payload = [
            'course_name' => isset($data['course_name']) && is_string($data['course_name']) ? $data['course_name'] : '',
            'previous_result' => $data['previous_result'],
            'conversation' => refine_analysis::normalize_conversation($data['conversation'] ?? []),
            'feedback' => $feedback,
        ];

        if (isset($data['source_summary']) && is_string($data['source_summary']) && $data['source_summary'] !== '') {
            $payload['source_summary'] = $data['source_summary'];
        }

        if (isset($data['context']) && is_array($data['context'])) {
            $creationcontext = \block_mastermind_assistant\local\course_context::from_array($data['context']);
            if (!empty($creationcontext)) {
                $payload['context'] = $creationcontext;
            }
        }

        return $payload;
    }

    /**
     * Returns description of method result value
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'preview' => new external_value(PARAM_RAW, 'JSON-encoded refined structure preview', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
