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
 * Report download builder (XLSX / CSV / PDF) for the AI report builder.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\local;

/**
 * Validates, re-executes and streams a report as a download.
 *
 * The client may only replay SQL it was given by generate_report, but it is
 * never trusted: every export re-validates the statement through
 * report_sql_validator and re-executes it with :courseid bound server-side
 * from the validated course context.
 */
class report_exporter {
    /** @var int Hard cap on exported rows, matching generate_report. */
    public const MAX_ROWS = 500;

    /** @var string[] Supported export formats. */
    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    /** @var int Column count above which the PDF switches to landscape. */
    public const PDF_LANDSCAPE_THRESHOLD = 6;

    /**
     * Validate the client-supplied SQL and execute it.
     *
     * @param string $sql Untrusted SQL replayed by the client.
     * @param int $courseid Validated course ID bound to :courseid.
     * @return array [string[] $columns (key => label), array[] $rows (key => value)]
     * @throws \invalid_parameter_exception When the SQL fails validation.
     */
    public static function prepare(string $sql, int $courseid): array {
        global $DB;

        $verdict = report_sql_validator::validate($sql);
        if (!$verdict['ok']) {
            throw new \invalid_parameter_exception('Report SQL rejected: ' . $verdict['reason']);
        }

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, self::MAX_ROWS);

        $columns = [];
        $rows = [];
        foreach ($records as $record) {
            $record = (array) $record;
            if (empty($columns)) {
                foreach (array_keys($record) as $key) {
                    $columns[(string) $key] = (string) $key;
                }
            }
            $row = [];
            foreach (array_keys($columns) as $key) {
                $value = $record[$key] ?? null;
                $row[$key] = ($value === null) ? '' : (string) $value;
            }
            $rows[] = $row;
        }

        return [$columns, $rows];
    }

    /**
     * Validate, execute and stream the report in the requested format.
     *
     * @param int $courseid Validated course ID.
     * @param string $sql Untrusted SQL replayed by the client.
     * @param string $format One of 'xlsx', 'csv', 'pdf'.
     * @param string $title Report title used for the filename and PDF header.
     * @return void
     * @throws \invalid_parameter_exception On unknown format or rejected SQL.
     */
    public static function export(int $courseid, string $sql, string $format, string $title): void {
        if (!in_array($format, self::FORMATS, true)) {
            throw new \invalid_parameter_exception('Unsupported export format: ' . $format);
        }

        [$columns, $rows] = self::prepare($sql, $courseid);

        $title = trim($title);
        $filename = clean_filename($title !== '' ? $title : get_string('report_builder_title', 'block_mastermind_assistant'));

        if ($format === 'pdf') {
            self::send_pdf($filename, $title, $columns, $rows);
            return;
        }

        $dataformat = ($format === 'xlsx') ? 'excel' : 'csv';
        \core\dataformat::download_data($filename, $dataformat, $columns, new \ArrayIterator($rows));
    }

    /**
     * Stream the report as a PDF (core pdflib / TCPDF).
     *
     * Title and generation date header, simple table layout, landscape
     * orientation when the report has more than six columns.
     *
     * @param string $filename Download filename without extension.
     * @param string $title Report title.
     * @param string[] $columns Column key => label map.
     * @param array[] $rows Rows of key => string value.
     * @return void
     */
    protected static function send_pdf(string $filename, string $title, array $columns, array $rows): void {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $orientation = (count($columns) > self::PDF_LANDSCAPE_THRESHOLD) ? 'L' : 'P';
        $headertitle = ($title !== '') ? $title : get_string('report_builder_title', 'block_mastermind_assistant');
        $generatedon = get_string('report_generated_on', 'block_mastermind_assistant', userdate(time()));

        $doc = new \pdf($orientation);
        $doc->setPrintHeader(false);
        $doc->setPrintFooter(false);
        $doc->SetTitle($headertitle);
        $doc->SetMargins(10, 10);
        $doc->AddPage();

        $doc->SetFont('helvetica', 'B', 14);
        $doc->Write(0, $headertitle, '', false, 'L', true);
        $doc->SetFont('helvetica', '', 9);
        $doc->Write(0, $generatedon, '', false, 'L', true);
        $doc->Ln(4);

        $html = '<table border="0.5" cellpadding="3"><thead><tr>';
        foreach ($columns as $label) {
            $html .= '<th><b>' . s($label) . '</b></th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys($columns) as $key) {
                $html .= '<td>' . s($row[$key] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $doc->SetFont('helvetica', '', 8);
        $doc->writeHTML($html, true, false, false, false, '');

        $doc->Output($filename . '.pdf', 'D');
    }
}
