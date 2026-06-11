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
 * Download endpoint for AI report builder exports (XLSX / CSV / PDF).
 *
 * Downloads cannot be streamed through AJAX web services, so the client
 * POSTs the SQL it was given (plus format and title) here. The SQL is
 * NEVER trusted: it is re-validated through report_sql_validator and
 * re-executed with :courseid bound server-side from the validated course,
 * behind require_login + sesskey + the same capabilities as generation.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

// Locate Moodle's config.php. In a normal install __DIR__ resolves to
// <moodle>/blocks/mastermind_assistant/ and ../../config.php works. When the
// plugin is symlinked outside the Moodle tree (dev), the .. traversal through
// the symlink lands outside Moodle, so we derive Moodle's root from the
// request URL instead — no .. traversal involved.
require_once(
    is_file(__DIR__ . '/../../config.php')
        ? __DIR__ . '/../../config.php'
        : rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))) . '/config.php'
);

$courseid = required_param('courseid', PARAM_INT);
$sql = required_param('sql', PARAM_RAW);
$format = required_param('format', PARAM_ALPHA);
$title = optional_param('title', '', PARAM_TEXT);

$course = get_course($courseid);
require_login($course);
require_sesskey();

$context = context_course::instance($courseid);
require_capability('block/mastermind_assistant:view', $context);
require_capability('moodle/site:viewreports', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/mastermind_assistant/export_report.php', ['courseid' => $courseid]));

\block_mastermind_assistant\local\report_exporter::export($courseid, $sql, $format, $title);
