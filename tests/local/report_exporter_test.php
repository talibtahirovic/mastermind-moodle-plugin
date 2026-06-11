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
 * Tests for the report exporter validation gate.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Tests for the report exporter validation gate.
 *
 * The export endpoint receives SQL replayed by the client and must never
 * trust it: tampered statements have to be rejected by the same validator
 * that gates generation, before any database access.
 *
 * @covers \block_mastermind_assistant\local\report_exporter
 */
final class report_exporter_test extends \advanced_testcase {
    /**
     * Data provider: tampered SQL that prepare() must reject.
     *
     * @return array[]
     */
    public static function tampered_sql_provider(): array {
        return [
            'write statement smuggled in' => [
                'SELECT id FROM {user} WHERE id = :courseid; DELETE FROM {user}',
            ],
            'non-allow-listed table' => ['SELECT value FROM {config} WHERE id = :courseid'],
            'raw prefixed table' => ['SELECT id FROM mdl_user WHERE id = :courseid'],
            'sensitive column' => ['SELECT password FROM {user} WHERE id = :courseid'],
            'courseid filter stripped' => ['SELECT id, firstname FROM {user}'],
            'not a select' => ['DROP TABLE {user}'],
        ];
    }

    /**
     * Tampered client SQL is rejected before any database access.
     *
     * @dataProvider tampered_sql_provider
     * @param string $sql Tampered SQL.
     */
    public function test_prepare_rejects_tampered_sql(string $sql): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\invalid_parameter_exception::class);
        report_exporter::prepare($sql, (int) $course->id);
    }

    /**
     * Unknown formats are rejected before the SQL is even looked at.
     */
    public function test_export_rejects_unknown_format(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\invalid_parameter_exception::class);
        report_exporter::export(
            (int) $course->id,
            'SELECT id FROM {user} WHERE id = :courseid',
            'html',
            'Report'
        );
    }

    /**
     * Valid SQL executes with the server-bound courseid and returns
     * columns derived from the result set plus stringified rows.
     */
    public function test_prepare_executes_valid_sql(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $student1 = $generator->create_user(['firstname' => 'Ada']);
        $student2 = $generator->create_user(['firstname' => 'Grace']);
        $generator->enrol_user($student1->id, $course->id, 'student');
        $generator->enrol_user($student2->id, $course->id, 'student');

        $sql = 'SELECT u.id, u.firstname
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
              ORDER BY u.firstname ASC';

        [$columns, $rows] = report_exporter::prepare($sql, (int) $course->id);

        $this->assertSame(['id' => 'id', 'firstname' => 'firstname'], $columns);
        $this->assertCount(2, $rows);
        $this->assertSame('Ada', $rows[0]['firstname']);
        $this->assertSame('Grace', $rows[1]['firstname']);
        // All cell values are plain strings, ready for any writer.
        $this->assertContainsOnly('string', $rows[0]);
    }
}
