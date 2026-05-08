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
 * External function to create course from document
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
 * Create a new course from an uploaded document (PDF, DOCX, TXT).
 *
 * Uses helper methods from create_course_with_ai for course structure application.
 */
class create_course_from_document extends external_api {
    /**
     * Describe the parameters accepted by execute().
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'filedata' => new external_value(PARAM_RAW, 'Base64-encoded file content'),
            'filetype' => new external_value(PARAM_TEXT, 'MIME type of the file'),
            'filename' => new external_value(PARAM_TEXT, 'Original file name'),
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
     * Execute the web service call.
     *
     * @param string $filedata Base64-encoded file content.
     * @param string $filetype MIME type of the file.
     * @param string $filename Original file name.
     * @param int $categoryid Category ID.
     * @param bool $previewonly Return structure preview without creating course.
     * @return array
     */
    public static function execute($filedata, $filetype, $filename, $categoryid = 1, $previewonly = false) {
        global $DB;

        @set_time_limit(600);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'filedata' => $filedata,
                'filetype' => $filetype,
                'filename' => $filename,
                'categoryid' => $categoryid,
                'previewonly' => $previewonly,
            ]);

            $context = context_system::instance();
            self::validate_context($context);
            require_capability('moodle/course:create', $context);

            // Validate file type.
            $allowedtypes = [
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain',
            ];
            if (!in_array($params['filetype'], $allowedtypes)) {
                throw new Exception('Unsupported file type. Please upload a PDF, DOCX, or TXT file.');
            }

            // Validate file size (base64 is ~33% larger than raw).
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

            // Send to dashboard for text extraction + AI generation.
            $client = new \block_mastermind_assistant\api_client();
            $aistructure = $client->generate_course_from_document(
                $params['filedata'],
                $params['filetype'],
                $params['filename']
            );

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

            $defaultname = pathinfo($params['filename'], PATHINFO_FILENAME);
            return create_course_with_ai::build_and_apply($aistructure, $params['categoryid'], $defaultname);
        } catch (Exception $e) {
            debugging("Error creating course from document: " . $e->getMessage());
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
            'preview' => new external_value(PARAM_RAW, 'JSON-encoded AI structure preview', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
