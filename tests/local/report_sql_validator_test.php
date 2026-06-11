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
                'SELECT u.id, u.timecreated AS created FROM {user} u WHERE u.id = :courseid',
            ],
            'alias minto does not trip INTO' => [
                'SELECT gg.finalgrade AS minto FROM {grade_grades} gg WHERE gg.itemid = :courseid',
            ],
            'deleted column does not trip DELETE' => [
                'SELECT id FROM {user} WHERE deleted = 0 AND id = :courseid',
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

        $this->assertNull(report_sql_validator::check_denied_columns('SELECT firstname FROM {user}'));
        $this->assertNotNull(report_sql_validator::check_denied_columns('SELECT PASSWORD FROM {user}'));

        $this->assertNull(report_sql_validator::check_courseid_param('WHERE c.id = :courseid'));
        $this->assertNotNull(report_sql_validator::check_courseid_param('WHERE c.id = 1'));
    }

    /**
     * Every allow-listed token validates and nothing unexpected is listed.
     */
    public function test_all_allow_listed_tables_accepted(): void {
        $this->assertCount(32, report_sql_validator::ALLOWED_TABLES);
        foreach (report_sql_validator::ALLOWED_TABLES as $table) {
            $sql = 'SELECT id FROM {' . $table . '} WHERE id = :courseid';
            $result = report_sql_validator::validate($sql);
            $this->assertTrue($result['ok'], '{' . $table . '} should be allowed: ' . ($result['reason'] ?? ''));
        }
        $this->assertNotContains('config', report_sql_validator::ALLOWED_TABLES);
        $this->assertNotContains('sessions', report_sql_validator::ALLOWED_TABLES);
        $this->assertNotContains('external_tokens', report_sql_validator::ALLOWED_TABLES);
    }
}
