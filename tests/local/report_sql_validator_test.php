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
 * Tests for the report SQL validation gate.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Tests for the report SQL validation gate.
 *
 * This validator is the security boundary between AI-generated SQL and the
 * site database, so the rejection cases are exercised exhaustively: every
 * denied keyword, every dangerous character sequence, table allow-list
 * violations, raw-prefix references, sensitive columns and the mandatory
 * :courseid parameter.
 *
 * @covers \block_mastermind_assistant\local\report_sql_validator
 */
final class report_sql_validator_test extends \advanced_testcase {
    /**
     * Data provider: statements that must be accepted.
     *
     * @return array[]
     */
    public static function accepted_sql_provider(): array {
        return [
            'simple select on {user}' => [
                'SELECT u.id, u.firstname, u.lastname
                   FROM {user} u
                   JOIN {user_enrolments} ue ON ue.userid = u.id
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid AND u.deleted = 0',
            ],
            'joins across several allow-listed tables' => [
                'SELECT u.id, u.firstname, qa.sumgrades, q.name
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
                   JOIN {user} u ON u.id = qa.userid
                   JOIN {grade_grades} gg ON gg.userid = u.id
                   JOIN {grade_items} gi ON gi.id = gg.itemid
                  WHERE q.course = :courseid
               ORDER BY qa.sumgrades DESC',
            ],
            'lowercase select' => [
                'select c.fullname from {course} c where c.id = :courseid',
            ],
            'limit clause' => [
                'SELECT id, fullname FROM {course} WHERE id = :courseid LIMIT 100',
            ],
            'alias created does not trip CREATE' => [
                'SELECT u.id, u.timecreated AS created
                   FROM {user} u
                   JOIN {user_enrolments} ue ON ue.userid = u.id
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid',
            ],
            'alias minto does not trip INTO' => [
                'SELECT gg.finalgrade AS minto FROM {grade_grades} gg WHERE gg.itemid = :courseid',
            ],
            'deleted column does not trip DELETE' => [
                'SELECT u.id
                   FROM {user} u
                   JOIN {user_enrolments} ue ON ue.userid = u.id
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid AND u.deleted = 0',
            ],
            'enrolled students roster' => [
                'SELECT u.firstname, u.lastname
                   FROM {user} u
                   JOIN {user_enrolments} ue ON ue.userid = u.id
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :courseid',
            ],
            'courseid on the left of the equality' => [
                'SELECT c.fullname FROM {course} c WHERE :courseid = c.id',
            ],
            'courseid as an IN member' => [
                'SELECT c.fullname FROM {course} c WHERE c.id IN (:courseid)',
            ],
            'scorm 4.3+ attempt/value tables' => [
                'SELECT u.id, u.firstname, sv.value
                   FROM {scorm_attempt} sa
                   JOIN {scorm_scoes_value} sv ON sv.attemptid = sa.id
                   JOIN {scorm} s ON s.id = sa.scormid
                   JOIN {user} u ON u.id = sa.userid
                  WHERE s.course = :courseid',
            ],
        ];
    }

    /**
     * Valid statements pass the gate.
     *
     * @dataProvider accepted_sql_provider
     * @param string $sql Candidate SQL.
     */
    public function test_validate_accepts(string $sql): void {
        $result = report_sql_validator::validate($sql);
        $this->assertTrue($result['ok'], 'Expected acceptance, got: ' . ($result['reason'] ?? ''));
        $this->assertNull($result['reason']);
    }

    /**
     * Data provider: every denied keyword, lower- and mixed-case.
     *
     * @return array[]
     */
    public static function denied_keyword_provider(): array {
        $cases = [];
        foreach (report_sql_validator::DENIED_KEYWORDS as $keyword) {
            $cases['keyword ' . $keyword] = [
                'SELECT id FROM {user} WHERE id = :courseid AND ' . strtolower($keyword) . ' = 1',
            ];
        }
        return $cases;
    }

    /**
     * All 17 denied keywords are rejected, case-insensitively.
     *
     * @dataProvider denied_keyword_provider
     * @param string $sql Candidate SQL containing a denied keyword.
     */
    public function test_validate_rejects_denied_keywords(string $sql): void {
        $result = report_sql_validator::validate($sql);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('keyword', $result['reason']);
    }

    /**
     * The deny-list itself covers the 17 contract keywords.
     */
    public function test_denied_keyword_list_is_complete(): void {
        $expected = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT',
            'REVOKE', 'EXECUTE', 'CALL', 'INTO', 'OUTFILE', 'LOAD_FILE', 'SLEEP',
            'BENCHMARK', 'UNION',
        ];
        $this->assertSame($expected, report_sql_validator::DENIED_KEYWORDS);
        $this->assertCount(17, report_sql_validator::DENIED_KEYWORDS);
    }

    /**
     * Data provider: statements that must be rejected, with the expected
     * fragment of the rejection reason.
     *
     * @return array[]
     */
    public static function rejected_sql_provider(): array {
        return [
            'empty string' => ['', 'empty'],
            'whitespace only' => ["   \n\t  ", 'empty'],
            'does not start with SELECT' => [
                'WITH x AS (SELECT 1) SELECT id FROM {user} WHERE id = :courseid', 'SELECT',
            ],
            'selectx is not SELECT' => ['SELECTX id FROM {user} WHERE id = :courseid', 'SELECT'],
            'semicolon' => ['SELECT id FROM {user} WHERE id = :courseid;', 'semicolon'],
            'INSERT smuggled after SELECT' => [
                'SELECT id FROM {user} WHERE id = :courseid; INSERT {user} (id) VALUES (1)', 'semicolon',
            ],
            'line comment' => ['SELECT id FROM {user} -- hidden' . "\n" . 'WHERE id = :courseid', 'comment'],
            'block comment open' => ['SELECT /* sneaky */ id FROM {user} WHERE id = :courseid', 'comment'],
            'backslash escape' => [
                "SELECT id FROM {user} WHERE firstname = 'a\\nb' AND id = :courseid", 'backslash',
            ],
            'union (MVP deny)' => [
                'SELECT id FROM {user} WHERE id = :courseid UNION SELECT id FROM {course}', 'UNION',
            ],
            'non-allow-listed table {config}' => [
                'SELECT value FROM {config} WHERE id = :courseid', 'allow-list',
            ],
            'non-allow-listed table {sessions}' => [
                'SELECT id FROM {sessions} WHERE userid = :courseid', 'allow-list',
            ],
            'removed 4.2 table {scorm_scoes_track}' => [
                'SELECT id FROM {scorm_scoes_track} WHERE scormid = :courseid', 'allow-list',
            ],
            'empty placeholder' => ['SELECT id FROM {} WHERE id = :courseid', 'allow-list'],
            'uppercase placeholder is not an exact match' => [
                'SELECT id FROM {User} WHERE id = :courseid', 'allow-list',
            ],
            'raw mdl_user reference' => [
                'SELECT id FROM mdl_user WHERE id = :courseid', 'raw prefixed',
            ],
            'raw prefix alongside placeholder' => [
                'SELECT u.id FROM {user} u JOIN mdl_config c ON c.id = u.id WHERE u.id = :courseid', 'raw prefixed',
            ],
            'missing :courseid' => ['SELECT id FROM {user} WHERE deleted = 0', ':courseid'],
            'denied column password' => [
                'SELECT u.password FROM {user} u WHERE u.id = :courseid', 'password',
            ],
            'denied column secret' => [
                'SELECT e.secret FROM {enrol} e WHERE e.courseid = :courseid', 'secret',
            ],
            'denied column sessdata' => [
                'SELECT sessdata FROM {user} WHERE id = :courseid', 'sessdata',
            ],
            'denied column token' => [
                'SELECT token FROM {user} WHERE id = :courseid', 'token',
            ],
            'plural tokens still rejected (conservative)' => [
                "SELECT firstname AS tokens FROM {user} WHERE id = :courseid", 'token',
            ],
            'information_schema exploit' => [
                'SELECT table_name FROM information_schema.tables WHERE :courseid > 0', 'information_schema',
            ],
            'mysql system database exploit' => [
                'SELECT user, host FROM mysql.user WHERE :courseid > 0', 'mysql.',
            ],
            'pg_catalog exploit' => [
                'SELECT rolname FROM pg_catalog.pg_authid WHERE :courseid > 0', 'pg_catalog',
            ],
            'derived table after FROM' => [
                'SELECT x.id FROM (SELECT id FROM {course}) x WHERE x.id = :courseid', '{placeholder}',
            ],
            'bare table name after FROM' => [
                'SELECT email FROM user WHERE id = :courseid', '{placeholder}',
            ],
            'bare table name after JOIN' => [
                'SELECT c.id FROM {course} c JOIN course_dupe d ON d.id = c.id WHERE c.id = :courseid',
                '{placeholder}',
            ],
            'courseid present but never compared (two rules violated)' => [
                'SELECT firstname, lastname, email FROM {user} WHERE :courseid > 0', 'equality filter',
            ],
            'courseid only in the SELECT list' => [
                'SELECT email, :courseid AS cid FROM {user}', 'equality filter',
            ],
            '{user} compared to courseid but no course-scoped join' => [
                'SELECT u.email FROM {user} u WHERE u.id = :courseid', 'course-scoped',
            ],
        ];
    }

    /**
     * Invalid statements are rejected with a meaningful reason.
     *
     * @dataProvider rejected_sql_provider
     * @param string $sql Candidate SQL.
     * @param string $reasonfragment Expected fragment of the rejection reason.
     */
    public function test_validate_rejects(string $sql, string $reasonfragment): void {
        $result = report_sql_validator::validate($sql);
        $this->assertFalse($result['ok'], 'Expected rejection of: ' . $sql);
        $this->assertNotNull($result['reason']);
        $this->assertStringContainsStringIgnoringCase($reasonfragment, $result['reason']);
    }

    /**
     * Each check is individually callable (null = pass, string = reason).
     */
    public function test_individual_checks(): void {
        $this->assertNull(report_sql_validator::check_starts_with_select('SELECT 1'));
        $this->assertNotNull(report_sql_validator::check_starts_with_select('DELETE FROM x'));

        $this->assertNull(report_sql_validator::check_dangerous_characters('SELECT 1'));
        $this->assertNotNull(report_sql_validator::check_dangerous_characters('SELECT 1;'));

        $this->assertNull(report_sql_validator::check_denied_keywords('SELECT timecreated AS created'));
        $this->assertNotNull(report_sql_validator::check_denied_keywords('SELECT 1 UNION SELECT 2'));

        $this->assertNull(report_sql_validator::check_table_tokens('SELECT id FROM {user}'));
        $this->assertNotNull(report_sql_validator::check_table_tokens('SELECT id FROM {config}'));

        $this->assertNull(report_sql_validator::check_raw_prefix('SELECT id FROM {user}'));
        $this->assertNotNull(report_sql_validator::check_raw_prefix('SELECT id FROM MDL_USER'));

        $this->assertNull(report_sql_validator::check_denied_schemas('SELECT id FROM {user}'));
        $this->assertNotNull(report_sql_validator::check_denied_schemas('SELECT 1 FROM INFORMATION_SCHEMA.TABLES'));
        $this->assertNotNull(report_sql_validator::check_denied_schemas('SELECT 1 FROM pg_catalog.pg_authid'));
        $this->assertNotNull(report_sql_validator::check_denied_schemas('SELECT 1 FROM PG_SHADOW'));
        $this->assertNotNull(report_sql_validator::check_denied_schemas('SELECT 1 FROM mysql.user'));

        $this->assertNull(report_sql_validator::check_from_join_placeholder('SELECT id FROM {user}'));
        $this->assertNull(report_sql_validator::check_from_join_placeholder("SELECT id\nFROM\n{user}"));
        $this->assertNotNull(report_sql_validator::check_from_join_placeholder('SELECT id FROM user'));
        $this->assertNotNull(report_sql_validator::check_from_join_placeholder('SELECT id FROM (SELECT 1) x'));
        $this->assertNotNull(report_sql_validator::check_from_join_placeholder(
            'SELECT id FROM {user} u JOIN other o ON o.id = u.id'
        ));

        $this->assertNull(report_sql_validator::check_denied_columns('SELECT firstname FROM {user}'));
        $this->assertNotNull(report_sql_validator::check_denied_columns('SELECT PASSWORD FROM {user}'));

        $this->assertNull(report_sql_validator::check_courseid_param('WHERE c.id = :courseid'));
        $this->assertNotNull(report_sql_validator::check_courseid_param('WHERE c.id = 1'));

        $this->assertNull(report_sql_validator::check_courseid_comparison('WHERE c.id = :courseid'));
        $this->assertNull(report_sql_validator::check_courseid_comparison('WHERE :courseid = c.id'));
        $this->assertNull(report_sql_validator::check_courseid_comparison('WHERE c.id IN (:courseid)'));
        $this->assertNotNull(report_sql_validator::check_courseid_comparison('WHERE :courseid > 0'));
        $this->assertNotNull(report_sql_validator::check_courseid_comparison('SELECT :courseid AS cid'));

        $this->assertNull(report_sql_validator::check_user_course_scope('SELECT id FROM {course}'));
        $this->assertNull(report_sql_validator::check_user_course_scope(
            'SELECT u.id FROM {user} u JOIN {user_enrolments} ue ON ue.userid = u.id'
        ));
        $this->assertNotNull(report_sql_validator::check_user_course_scope('SELECT email FROM {user}'));
    }

    /**
     * Every allow-listed token validates and nothing unexpected is listed.
     */
    public function test_all_allow_listed_tables_accepted(): void {
        $this->assertCount(35, report_sql_validator::ALLOWED_TABLES);
        foreach (report_sql_validator::ALLOWED_TABLES as $table) {
            if ($table === 'user') {
                // The {user} table additionally requires a course-scoped join.
                $sql = 'SELECT u.id FROM {user} u'
                    . ' JOIN {user_enrolments} ue ON ue.userid = u.id'
                    . ' JOIN {enrol} e ON e.id = ue.enrolid'
                    . ' WHERE e.courseid = :courseid';
            } else {
                $sql = 'SELECT id FROM {' . $table . '} WHERE id = :courseid';
            }
            $result = report_sql_validator::validate($sql);
            $this->assertTrue($result['ok'], '{' . $table . '} should be allowed: ' . ($result['reason'] ?? ''));
        }
        $this->assertNotContains('config', report_sql_validator::ALLOWED_TABLES);
        $this->assertNotContains('sessions', report_sql_validator::ALLOWED_TABLES);
        $this->assertNotContains('external_tokens', report_sql_validator::ALLOWED_TABLES);
        // Removed in Moodle 4.3 — replaced by scorm_attempt/scorm_scoes_value.
        $this->assertNotContains('scorm_scoes_track', report_sql_validator::ALLOWED_TABLES);
    }

    /**
     * Every course-scoped anchor table must itself be on the allow-list,
     * otherwise satisfying the {user} scoping rule would be impossible.
     */
    public function test_course_scoped_tables_are_allow_listed(): void {
        foreach (report_sql_validator::COURSE_SCOPED_TABLES as $table) {
            $this->assertContains($table, report_sql_validator::ALLOWED_TABLES);
        }
        $this->assertNotContains('user', report_sql_validator::COURSE_SCOPED_TABLES);
        $this->assertNotContains('course', report_sql_validator::COURSE_SCOPED_TABLES);
    }

    /**
     * A single :courseid occurrence is rewritten to :courseid0 with one binding.
     */
    public function test_prepare_courseid_bindings_single_occurrence(): void {
        [$sql, $params] = report_sql_validator::prepare_courseid_bindings(
            'SELECT id FROM {course} WHERE id = :courseid',
            7
        );

        $this->assertSame('SELECT id FROM {course} WHERE id = :courseid0', $sql);
        $this->assertSame(['courseid0' => 7], $params);
    }

    /**
     * Repeated occurrences get unique names so Moodle's DML layer accepts
     * the statement (it forbids reusing one named parameter).
     */
    public function test_prepare_courseid_bindings_multiple_occurrences(): void {
        $original = 'SELECT u.id'
            . ' FROM {user} u'
            . ' JOIN {user_enrolments} ue ON ue.userid = u.id'
            . ' JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid'
            . ' JOIN {grade_grades} gg ON gg.userid = u.id'
            . ' JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = :courseid'
            . ' WHERE u.deleted = 0 AND :courseid = e.courseid';

        [$sql, $params] = report_sql_validator::prepare_courseid_bindings($original, 42);

        $this->assertStringNotContainsString(':courseid ', $sql . ' ');
        $this->assertStringContainsString('e.courseid = :courseid0', $sql);
        $this->assertStringContainsString('gi.courseid = :courseid1', $sql);
        $this->assertStringContainsString(':courseid2 = e.courseid', $sql);
        $this->assertSame(['courseid0' => 42, 'courseid1' => 42, 'courseid2' => 42], $params);
    }

    /**
     * Occurrences adjacent to punctuation are still rewritten — the word
     * boundary sits between the parameter name and the punctuation.
     */
    public function test_prepare_courseid_bindings_handles_punctuation(): void {
        [$sql, $params] = report_sql_validator::prepare_courseid_bindings(
            'SELECT id FROM {course} WHERE (id = :courseid) AND id IN (:courseid,:courseid)',
            5
        );

        $this->assertSame(
            'SELECT id FROM {course} WHERE (id = :courseid0) AND id IN (:courseid1,:courseid2)',
            $sql
        );
        $this->assertSame(['courseid0' => 5, 'courseid1' => 5, 'courseid2' => 5], $params);
    }

    /**
     * A dashboard-invented name like :courseid2 is NOT rewritten (no word
     * boundary after 'courseid'); it fails parameter binding exactly as it
     * would today, instead of being silently renamed.
     */
    public function test_prepare_courseid_bindings_ignores_suffixed_names(): void {
        [$sql, $params] = report_sql_validator::prepare_courseid_bindings(
            'SELECT id FROM {course} WHERE id = :courseid AND category = :courseid2',
            9
        );

        $this->assertSame(
            'SELECT id FROM {course} WHERE id = :courseid0 AND category = :courseid2',
            $sql
        );
        $this->assertSame(['courseid0' => 9], $params);
    }

    /**
     * No :courseid at all (cannot pass validation, but the helper must be
     * harmless): the SQL is untouched and the bindings are empty.
     */
    public function test_prepare_courseid_bindings_without_occurrences(): void {
        [$sql, $params] = report_sql_validator::prepare_courseid_bindings(
            'SELECT id FROM {course} WHERE id = 1',
            3
        );

        $this->assertSame('SELECT id FROM {course} WHERE id = 1', $sql);
        $this->assertSame([], $params);
    }
}
