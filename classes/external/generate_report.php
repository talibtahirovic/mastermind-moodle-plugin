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
 * External function for the AI report builder.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use block_mastermind_assistant\local\report_sql_validator;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_course;
use Exception;

/**
 * Generate a custom course report from a natural-language request.
 *
 * The dashboard translates the request into candidate SQL; this function
 * validates that SQL through report_sql_validator (the real security gate),
 * executes it read-only with :courseid bound server-side from the validated
 * course context, and returns the result rows. A validation or execution
 * failure triggers exactly one automatic repair round-trip to the dashboard
 * before giving up.
 */
class generate_report extends external_api {
    /** @var int Hard cap on returned rows, regardless of any LIMIT in the SQL. */
    public const MAX_ROWS = 500;

    /** @var int Maximum number of activity names sent as dashboard context. */
    public const MAX_ACTIVITY_NAMES = 50;

    /**
     * Describe the parameters accepted by execute().
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'request' => new external_value(PARAM_RAW, 'Natural-language report request'),
            'conversation' => new external_value(PARAM_RAW, 'Conversation history JSON', VALUE_DEFAULT, '[]'),
            'previous_sql' => new external_value(PARAM_RAW, 'SQL of the previous result (refinements)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the web service call.
     *
     * @param int $courseid Course ID.
     * @param string $request Natural-language report request.
     * @param string $conversation Conversation history JSON.
     * @param string $previoussql SQL of the previous result (refinements).
     * @return array
     */
    public static function execute($courseid, $request, $conversation = '[]', $previoussql = '') {
        \core_php_time_limit::raise(600);

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
                'request' => $request,
                'conversation' => $conversation,
                'previous_sql' => $previoussql,
            ]);

            $context = context_course::instance($params['courseid']);
            self::validate_context($context);
            require_capability('block/mastermind_assistant:view', $context);
            require_capability('moodle/site:viewreports', $context);

            $requesttext = trim($params['request']);
            if ($requesttext === '') {
                throw new Exception('Report request must not be empty');
            }

            $payload = self::build_payload(
                $params['courseid'],
                $requesttext,
                $params['conversation'],
                $params['previous_sql']
            );

            $client = new \block_mastermind_assistant\api_client();
            $requester = function (array $p) use ($client): array {
                return $client->generate_report($p);
            };

            return self::run_generation($requester, $payload, $params['courseid']);
        } catch (Exception $e) {
            debugging('generate_report error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate with one automatic repair round-trip.
     *
     * Calls the dashboard, validates and executes the returned SQL. On a
     * validation OR execution failure the dashboard is called once more with
     * a repair hint {sql, error}; a second failure returns the friendly
     * 'report_failed' message. Maximum two dashboard calls per invocation.
     *
     * @param callable $requester fn(array $payload): array — the dashboard call.
     * @param array $payload Dashboard request payload (without repair key).
     * @param int $courseid Validated course ID for the :courseid binding.
     * @return array External response structure.
     */
    public static function run_generation(callable $requester, array $payload, int $courseid): array {
        $response = $requester($payload);
        $processed = self::process_dashboard_response(is_array($response) ? $response : [], $courseid);

        if (!$processed['ok']) {
            $repairpayload = $payload;
            $repairpayload['repair'] = [
                'sql' => $processed['sql'],
                'error' => $processed['error'],
            ];
            $response = $requester($repairpayload);
            $processed = self::process_dashboard_response(is_array($response) ? $response : [], $courseid);
        }

        if (!$processed['ok']) {
            return [
                'success' => false,
                'message' => get_string('report_failed', 'block_mastermind_assistant'),
            ];
        }

        return $processed['data'];
    }

    /**
     * Validate and execute one dashboard response.
     *
     * The SQL is untrusted: it must pass report_sql_validator before
     * execution, and :courseid is bound from the server-side validated
     * course id — never from the SQL text or any client value.
     *
     * @param array $response Decoded dashboard response.
     * @param int $courseid Validated course ID.
     * @return array ['ok' => bool, 'sql' => string, 'error' => string, 'data' => array|null]
     */
    public static function process_dashboard_response(array $response, int $courseid): array {
        global $DB;

        $sql = (isset($response['sql']) && is_string($response['sql'])) ? $response['sql'] : '';

        $verdict = report_sql_validator::validate($sql);
        if (!$verdict['ok']) {
            return [
                'ok' => false,
                'sql' => $sql,
                'error' => 'SQL validation failed: ' . $verdict['reason'],
                'data' => null,
            ];
        }

        try {
            $records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, self::MAX_ROWS);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'sql' => $sql,
                'error' => 'SQL execution failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }

        $columns = [];
        foreach ((array) ($response['columns'] ?? []) as $column) {
            if (!is_array($column) || !isset($column['key']) || $column['key'] === '') {
                continue;
            }
            $columns[] = [
                'key' => (string) $column['key'],
                'label' => (string) (($column['label'] ?? '') !== '' ? $column['label'] : $column['key']),
            ];
        }
        if (empty($columns) && !empty($records)) {
            foreach (array_keys((array) reset($records)) as $key) {
                $columns[] = ['key' => (string) $key, 'label' => (string) $key];
            }
        }

        $rows = [];
        foreach ($records as $record) {
            $record = (array) $record;
            $row = [];
            foreach ($columns as $column) {
                $value = $record[$column['key']] ?? null;
                $row[] = ($value === null) ? '' : (string) $value;
            }
            $rows[] = $row;
        }

        return [
            'ok' => true,
            'sql' => $sql,
            'error' => '',
            'data' => [
                'success' => true,
                'title' => trim((string) ($response['title'] ?? '')),
                'explanation' => trim((string) ($response['explanation'] ?? '')),
                'columns' => $columns,
                'rows' => $rows,
                'total_rows' => count($rows),
                'sql' => $sql,
            ],
        ];
    }

    /**
     * Build the dashboard request payload entirely server-side.
     *
     * @param int $courseid Validated course ID.
     * @param string $request Natural-language report request.
     * @param string $conversationjson Conversation history JSON from the client.
     * @param string $previoussql SQL of the previous result (refinements).
     * @return array Dashboard payload.
     */
    public static function build_payload(int $courseid, string $request, string $conversationjson, string $previoussql): array {
        global $CFG;

        $course = get_course($courseid);

        $payload = [
            'request' => $request,
            'dbtype' => self::map_dbtype((string) $CFG->dbtype),
            'course_name' => format_string($course->fullname),
            'activity_names' => self::get_activity_names($courseid),
            'conversation' => refine_analysis::normalize_conversation(json_decode($conversationjson, true)),
        ];

        if (trim($previoussql) !== '') {
            $payload['previous_sql'] = $previoussql;
        }

        return $payload;
    }

    /**
     * Map Moodle's database driver name to the dashboard's dialect value.
     *
     * @param string $dbtype Value of $CFG->dbtype (e.g. mysqli, mariadb, pgsql).
     * @return string One of 'mysql', 'mariadb', 'pgsql'.
     */
    public static function map_dbtype(string $dbtype): string {
        switch ($dbtype) {
            case 'mariadb':
                return 'mariadb';
            case 'pgsql':
                return 'pgsql';
            case 'mysqli':
            default:
                return 'mysql';
        }
    }

    /**
     * Names of the course's visible activities (light context so the
     * dashboard can resolve references like "quiz X"), capped at 50.
     *
     * @param int $courseid Course ID.
     * @return string[] Activity names.
     */
    public static function get_activity_names(int $courseid): array {
        $names = [];
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->visible || $cm->deletioninprogress) {
                continue;
            }
            $names[] = format_string($cm->name);
            if (count($names) >= self::MAX_ACTIVITY_NAMES) {
                break;
            }
        }
        return $names;
    }

    /**
     * Describe the return value of execute().
     *
     * @return \external_description
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'title' => new external_value(PARAM_TEXT, 'Report title', VALUE_OPTIONAL),
            'explanation' => new external_value(PARAM_RAW, 'Plain-language explanation of the report', VALUE_OPTIONAL),
            'columns' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_RAW, 'Result column key'),
                    'label' => new external_value(PARAM_RAW, 'Human-readable column label'),
                ]),
                'Result columns',
                VALUE_OPTIONAL
            ),
            'rows' => new external_multiple_structure(
                new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Cell value (client must escape on render)')
                ),
                'Result rows (capped at 500)',
                VALUE_OPTIONAL
            ),
            'total_rows' => new external_value(PARAM_INT, 'Total number of returned rows', VALUE_OPTIONAL),
            'sql' => new external_value(PARAM_RAW, 'The validated SQL (needed for export)', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}
