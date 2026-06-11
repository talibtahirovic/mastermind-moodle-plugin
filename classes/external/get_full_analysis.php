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
 * External function to get full course analysis
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
 * Single Moodle web-service call for full AI analysis.
 *
 * Tries the single /api/ma/full-analysis dashboard endpoint first (1 call).
 * If the endpoint doesn't exist yet, falls back to the two existing
 * endpoints (analyze-course + generate-structure = 2 calls).
 */
class get_full_analysis extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'coursedata' => new external_value(PARAM_RAW, 'Course data JSON'),
        ]);
    }

    /**
     * Execute the web service call.
     *
     * @param int $courseid Course ID.
     * @param string $coursedata Course data JSON.
     * @return array
     */
    public static function execute($courseid, $coursedata) {
        @set_time_limit(600);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
                'coursedata' => $coursedata,
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

            $data = json_decode($params['coursedata'], true);
            if (!$data) {
                throw new Exception('Invalid course data JSON');
            }

            $client = new \block_mastermind_assistant\api_client();

            // Try single endpoint first (1 dashboard call).
            $singleendpointworked = false;
            try {
                $airesponse = $client->full_analysis($data);
                $singleendpointworked = true;
            } catch (Exception $e) {
                // If the endpoint doesn't exist (404) or isn't supported,
                // fall back to the two-step approach.
                debugging('full-analysis endpoint not available, falling back to 2-step: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            if ($singleendpointworked) {
                // Extract from single-endpoint response.
                $recommendationsstr = self::extract_recommendations($airesponse);
                $structurestr = self::extract_structure($airesponse);
            } else {
                // Fallback: 2 separate dashboard calls.
                $analysisresponse = $client->analyze_course($data);
                $recommendationsstr = self::extract_recommendations($analysisresponse);

                $structureresponse = $client->generate_structure($data, $recommendationsstr);
                $structurestr = self::extract_structure($structureresponse);
            }

            return [
                'success' => true,
                'recommendations' => $recommendationsstr,
                'structure' => $structurestr,
            ];
        } catch (Exception $e) {
            debugging('get_full_analysis error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'error' => 'AI Analysis Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Extract and normalize recommendations string from an API response.
     *
     * @param array $response API response payload.
     * @return string Recommendations as a string.
     */
    public static function extract_recommendations(array $response): string {
        $rec = $response;
        if (isset($response['recommendations'])) {
            $rec = $response['recommendations'];
        } else if (isset($response['analysis'])) {
            $rec = $response['analysis'];
        } else if (isset($response['result'])) {
            $rec = $response['result'];
        }
        return is_string($rec) ? $rec : json_encode($rec);
    }

    /**
     * Extract and normalize structure string from an API response.
     *
     * @param array $response API response payload.
     * @return string Structure encoded as a JSON string.
     */
    public static function extract_structure(array $response): string {
        $structure = $response;
        if (isset($response['structure']) && is_array($response['structure'])) {
            $structure = $response['structure'];
        } else if (isset($response['result']) && is_array($response['result'])) {
            $structure = $response['result'];
        }

        if (isset($structure['sections']) && is_array($structure['sections'])) {
            return json_encode($structure);
        }
        return is_string($structure) ? $structure : json_encode($structure);
    }

    /**
     * Describe the return value of execute().
     *
     * @return \external_description
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'recommendations' => new external_value(PARAM_RAW, 'AI recommendations', VALUE_OPTIONAL),
            'structure' => new external_value(PARAM_RAW, 'Updated course structure', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
