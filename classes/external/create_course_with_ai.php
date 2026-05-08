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
 * External function to create course with AI
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
use stdClass;

/**
 * External API for create course with ai.
 */
class create_course_with_ai extends external_api {
    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'coursename' => new external_value(PARAM_TEXT, 'Course name'),
            'categoryid' => new external_value(PARAM_INT, 'Category ID', VALUE_DEFAULT, 1),
            'previewonly' => new external_value(
                PARAM_BOOL,
                'Return structure preview without creating course',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Create a course with AI-generated structure via Dashboard API.
     * When previewonly=true, returns the AI structure without creating the course.
     * @param string $coursename
     * @param int $categoryid
     * @param bool $previewonly
     * @return array
     */
    public static function execute($coursename, $categoryid = 1, $previewonly = false) {
        global $DB;

        @set_time_limit(300);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'coursename' => $coursename,
                'categoryid' => $categoryid,
                'previewonly' => $previewonly,
            ]);

            $context = context_system::instance();
            self::validate_context($context);
            require_capability('moodle/course:create', $context);

            // Get AI-generated course structure from dashboard.
            $client = new \block_mastermind_assistant\api_client();
            $aistructure = $client->generate_course($params['coursename']);

            // Preview mode: return the structure without creating the course.
            if ($params['previewonly']) {
                return [
                    'success' => true,
                    'preview' => json_encode($aistructure),
                ];
            }

            // Validate category exists, fall back to default if not.
            if (!$DB->record_exists('course_categories', ['id' => $params['categoryid']])) {
                $params['categoryid'] = \core_course_category::get_default()->id;
            }

            // Build the course record, create it, apply AI structure — shared with
            // create_course_from_structure / create_course_from_document.
            return self::build_and_apply(
                $aistructure,
                $params['categoryid'],
                $params['coursename']
            );
        } catch (Exception $e) {
            debugging("Error creating course with AI: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Apply course structure from AI.
     * @param int $courseid
     * @param array $sections
     */
    public static function apply_course_structure(int $courseid, array $sections) {
        global $DB;

        $course = \get_course($courseid);

        // Preload all course sections in a single query, keyed by section number.
        $allsections = $DB->get_records('course_sections', ['course' => $courseid], 'section', '*');
        $sectionsbynumber = [];
        foreach ($allsections as $sec) {
            $sectionsbynumber[(int) $sec->section] = $sec;
        }

        $sectionnum = 1;
        foreach ($sections as $sectiondata) {
            $section = $sectionsbynumber[$sectionnum] ?? null;

            if ($section) {
                $section->name = $sectiondata['section_name'] ?? "Section $sectionnum";
                $section->summary = $sectiondata['description'] ?? '';
                $section->summaryformat = FORMAT_HTML;
                $DB->update_record('course_sections', $section);
            }

            if (!empty($sectiondata['activities'])) {
                foreach ($sectiondata['activities'] as $activity) {
                    self::create_activity($course, $section, $activity);
                }
            }

            $sectionnum++;
        }

        rebuild_course_cache($courseid, true);
    }

    /**
     * Create an activity in the course.
     * @param stdClass $course
     * @param stdClass $section
     * @param array $activitydata
     * @return bool
     */
    public static function create_activity($course, $section, $activitydata) {
        $modulename = self::map_activity_type($activitydata['moodle_type'] ?? 'page');
        $activityname = $activitydata['activity_name'] ?? 'Untitled Activity';

        $prepareddata = [
            'name' => $activityname,
            'moodle_type' => $modulename,
            'type' => $modulename,
            'status' => 'NEW',
            'intro' => $activitydata['description'] ?? '',
        ];

        $factory = new \block_mastermind_assistant\module_factory($course, $section);
        return $factory->create_from_ai($prepareddata);
    }

    /**
     * Map AI activity type to Moodle module.
     * @param string $aitype
     * @return string
     */
    public static function map_activity_type(string $aitype): string {
        $mapping = [
            'page' => 'page',
            'assignment' => 'assign',
            'quiz' => 'quiz',
            'forum' => 'forum',
            'resource' => 'resource',
            'label' => 'label',
            'lesson' => 'lesson',
            'workshop' => 'workshop',
            'wiki' => 'wiki',
            'glossary' => 'glossary',
            'feedback' => 'feedback',
        ];

        $normalized = strtolower(trim($aitype));
        return $mapping[$normalized] ?? 'page';
    }

    /**
     * Build the course record, call create_course(), apply the AI structure, and return the response array.
     *
     * Shared by create_course_from_structure and create_course_from_document to avoid duplication.
     *
     * @param array $aistructure AI-generated course structure array.
     * @param int $categoryid Validated category ID to place the course in.
     * @param string $defaultname Fallback course name when ai structure has no course_name.
     * @return array Standard response array with success, courseid, coursename, courseurl keys.
     */
    public static function build_and_apply(array $aistructure, int $categoryid, string $defaultname = 'Untitled Course'): array {
        $coursedata = new stdClass();
        $coursedata->fullname = $aistructure['course_name'] ?? $defaultname;
        $coursedata->shortname = self::generate_shortname($coursedata->fullname);
        $coursedata->category = $categoryid;
        $coursedata->summary = $aistructure['course_description'] ?? '';
        $coursedata->summaryformat = FORMAT_HTML;
        $coursedata->format = 'topics';
        $coursedata->numsections = count($aistructure['sections'] ?? []);
        $coursedata->startdate = time();
        $coursedata->visible = 1;
        $coursedata->enablecompletion = 1;

        $course = create_course($coursedata);

        // Apply AI-generated structure to the course.
        if (!empty($aistructure['sections'])) {
            self::apply_course_structure($course->id, $aistructure['sections']);
        }

        return [
            'success' => true,
            'courseid' => $course->id,
            'coursename' => $course->fullname,
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        ];
    }

    /**
     * Generate a unique shortname from fullname.
     * @param string $fullname
     * @return string
     */
    public static function generate_shortname(string $fullname): string {
        global $DB;

        $shortname = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $fullname), 0, 10));

        $counter = 1;
        $originalshortname = $shortname;
        while ($DB->record_exists('course', ['shortname' => $shortname])) {
            $shortname = $originalshortname . $counter;
            $counter++;
        }

        return $shortname;
    }

    /**
     * Returns description of method result value
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'courseid' => new external_value(PARAM_INT, 'Created course ID', VALUE_OPTIONAL),
            'coursename' => new external_value(PARAM_TEXT, 'Course name', VALUE_OPTIONAL),
            'courseurl' => new external_value(PARAM_URL, 'Course URL', VALUE_OPTIONAL),
            'preview' => new external_value(PARAM_RAW, 'JSON-encoded AI structure preview', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
