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
 * External function to create course from structure
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;
use Exception;

/**
 * Create a course from a previously previewed AI structure.
 *
 * Phase 2 of the preview flow: takes the JSON structure that was
 * returned by create_course_with_ai or create_course_from_document
 * in previewonly mode, and creates the actual course.
 */
class create_course_from_structure extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'structure' => new external_value(PARAM_RAW, 'JSON-encoded AI structure'),
            'categoryid' => new external_value(PARAM_INT, 'Category ID', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Execute the web service call.
     *
     * @param string $structure JSON-encoded AI structure.
     * @param int $categoryid Category ID.
     * @return array
     */
    public static function execute($structure, $categoryid = 1) {
        @set_time_limit(300);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'structure' => $structure,
                'categoryid' => $categoryid,
            ]);

            $context = context_system::instance();
            self::validate_context($context);
            require_capability('moodle/course:create', $context);

            $aistructure = json_decode($params['structure'], true);
            if ($aistructure === null) {
                throw new Exception('Invalid structure data.');
            }

            // Validate category exists, fall back to default if not.
            global $DB;
            if (!$DB->record_exists('course_categories', ['id' => $params['categoryid']])) {
                $params['categoryid'] = \core_course_category::get_default()->id;
            }

            return create_course_with_ai::build_and_apply($aistructure, $params['categoryid']);
        } catch (Exception $e) {
            debugging("Error creating course from structure: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Describe the return value of execute().
     *
     * @return \external_description
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'courseid' => new external_value(PARAM_INT, 'Created course ID', VALUE_OPTIONAL),
            'coursename' => new external_value(PARAM_TEXT, 'Course name', VALUE_OPTIONAL),
            'courseurl' => new external_value(PARAM_URL, 'Course URL', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
