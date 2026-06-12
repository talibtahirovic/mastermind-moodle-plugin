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
 * Strict validation gate for dashboard-generated report SQL.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\local;

/**
 * Validates AI-generated report SQL before it is allowed near the database.
 *
 * The dashboard produces *candidate* SQL only. This class is the real gate:
 * everything coming back from the dashboard (and everything replayed by the
 * client for exports) is treated as untrusted input and must pass every
 * check below. The query is executed read-only with the :courseid parameter
 * bound server-side from the validated course context — never from the SQL
 * text or any client value.
 */
class report_sql_validator {
    /**
     * Tables the generated SQL may reference via {placeholder} syntax.
     *
     * Reporting tables only — no config, sessions, secrets or auth tables.
     *
     * @var string[]
     */
    public const ALLOWED_TABLES = [
        'user',
        'course',
        'course_modules',
        'course_sections',
        'modules',
        'enrol',
        'user_enrolments',
        'role_assignments',
        'context',
        'grade_items',
        'grade_grades',
        'course_completions',
        'course_modules_completion',
        'quiz',
        'quiz_attempts',
        'quiz_grades',
        'question',
        'assign',
        'assign_submission',
        'assign_grades',
        'forum',
        'forum_discussions',
        'forum_posts',
        'lesson',
        'lesson_attempts',
        'glossary',
        'glossary_entries',
        'logstore_standard_log',
        'groups',
        'groups_members',
        'user_lastaccess',
        'scorm',
        'scorm_attempt',
        'scorm_scoes_value',
        'scorm_element',
    ];

    /**
     * Course-scoped tables that anchor {user} data to a single course.
     *
     * Any statement referencing {user} must also reference at least one of
     * these enrolment/activity tables, otherwise the :courseid filter cannot
     * actually scope the user rows and the query becomes a site-wide pull.
     *
     * @var string[]
     */
    public const COURSE_SCOPED_TABLES = [
        'user_enrolments',
        'role_assignments',
        'grade_grades',
        'course_completions',
        'course_modules_completion',
        'quiz_attempts',
        'quiz_grades',
        'assign_submission',
        'assign_grades',
        'forum_posts',
        'forum_discussions',
        'lesson_attempts',
        'glossary_entries',
        'scorm_attempt',
        'groups_members',
        'user_lastaccess',
        'logstore_standard_log',
    ];

    /**
     * Literal substrings that reveal a system-catalog or cross-database
     * reference. Belt-and-braces on top of the FROM/JOIN placeholder rule.
     *
     * @var string[]
     */
    public const DENIED_SCHEMAS = [
        'information_schema',
        'pg_catalog',
        'pg_',
        'mysql.',
    ];

    /**
     * Keywords that must not appear anywhere in the statement (word-boundary).
     *
     * UNION is denied too (MVP): it is the classic vehicle for smuggling a
     * second result set past a per-statement validator.
     *
     * @var string[]
     */
    public const DENIED_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'CREATE',
        'TRUNCATE',
        'GRANT',
        'REVOKE',
        'EXECUTE',
        'CALL',
        'INTO',
        'OUTFILE',
        'LOAD_FILE',
        'SLEEP',
        'BENCHMARK',
        'UNION',
    ];

    /**
     * Sensitive column-name prefixes that must never be selected.
     *
     * Matched with a leading word boundary only, so 'tokens' or 'passwords'
     * are rejected as well — deliberately conservative.
     *
     * @var string[]
     */
    public const DENIED_COLUMNS = [
        'password',
        'secret',
        'sessdata',
        'token',
    ];

    /**
     * Validate a candidate report SQL statement.
     *
     * @param string $sql Untrusted SQL with {table} placeholders.
     * @return array ['ok' => bool, 'reason' => string|null]
     */
    public static function validate(string $sql): array {
        $checks = [
            self::check_starts_with_select($sql),
            self::check_dangerous_characters($sql),
            self::check_denied_keywords($sql),
            self::check_table_tokens($sql),
            self::check_raw_prefix($sql),
            self::check_denied_schemas($sql),
            self::check_from_join_placeholder($sql),
            self::check_denied_columns($sql),
            self::check_courseid_param($sql),
            self::check_courseid_comparison($sql),
            self::check_user_course_scope($sql),
        ];

        foreach ($checks as $reason) {
            if ($reason !== null) {
                return ['ok' => false, 'reason' => $reason];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * The trimmed statement must start with SELECT (case-insensitive).
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_starts_with_select(string $sql): ?string {
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return 'Statement is empty';
        }
        if (!preg_match('/^select\b/i', $trimmed)) {
            return 'Statement must start with SELECT';
        }
        return null;
    }

    /**
     * No statement separators, SQL comments or backslash escapes.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_dangerous_characters(string $sql): ?string {
        $needles = [
            ';' => 'semicolon',
            '--' => 'SQL line comment',
            '/*' => 'SQL block comment',
            '*/' => 'SQL block comment',
            '\\' => 'backslash escape',
        ];
        foreach ($needles as $needle => $label) {
            if (strpos($sql, $needle) !== false) {
                return 'Statement contains a forbidden sequence: ' . $label;
            }
        }
        return null;
    }

    /**
     * None of the denied keywords may appear as a whole word.
     *
     * Word boundaries keep identifiers like an alias 'created' (CREATE) or
     * 'minto' (INTO) from tripping the check.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_denied_keywords(string $sql): ?string {
        foreach (self::DENIED_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $sql)) {
                return 'Statement contains a forbidden keyword: ' . $keyword;
            }
        }
        return null;
    }

    /**
     * Every {token} must exactly match the table allow-list.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_table_tokens(string $sql): ?string {
        if (!preg_match_all('/\{([^{}]*)\}/', $sql, $matches)) {
            return null;
        }
        foreach ($matches[1] as $token) {
            if (!in_array($token, self::ALLOWED_TABLES, true)) {
                return 'Table is not on the allow-list: {' . $token . '}';
            }
        }
        return null;
    }

    /**
     * Raw prefixed table references (mdl_...) bypass the placeholder
     * allow-list and are rejected outright.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_raw_prefix(string $sql): ?string {
        if (preg_match('/\bmdl_/i', $sql)) {
            return 'Statement references a raw prefixed table instead of a {placeholder}';
        }
        return null;
    }

    /**
     * System catalogs and cross-database references are denied outright,
     * regardless of where they appear in the statement.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_denied_schemas(string $sql): ?string {
        foreach (self::DENIED_SCHEMAS as $schema) {
            if (stripos($sql, $schema) !== false) {
                return 'Statement references a forbidden schema: ' . $schema;
            }
        }
        return null;
    }

    /**
     * Every FROM and JOIN must be immediately followed by an allow-listed
     * {placeholder} table.
     *
     * One structural rule forbids derived tables 'FROM (', bare table names
     * 'FROM user' and schema-qualified names 'FROM information_schema.tables'
     * at once: the only valid target after FROM/JOIN is a placeholder, and
     * check_table_tokens() already pins every placeholder to the allow-list.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_from_join_placeholder(string $sql): ?string {
        if (preg_match('/\b(?:from|join)\b(?!\s*\{)/i', $sql)) {
            return 'Every FROM/JOIN must reference an allow-listed {placeholder} table';
        }
        return null;
    }

    /**
     * Sensitive column names must not appear (leading word boundary, so
     * plural forms such as 'tokens' are rejected too).
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_denied_columns(string $sql): ?string {
        foreach (self::DENIED_COLUMNS as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '/i', $sql)) {
                return 'Statement references a denied column: ' . $column;
            }
        }
        return null;
    }

    /**
     * The statement must use the :courseid named parameter, which the plugin
     * binds server-side from the validated course context.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_courseid_param(string $sql): ?string {
        if (stripos($sql, ':courseid') === false) {
            return 'Statement must filter by the :courseid named parameter';
        }
        return null;
    }

    /**
     * The :courseid parameter must be used in a comparison context — an
     * equality on either side or as an IN(...) member.
     *
     * Presence alone is not enough: 'WHERE :courseid > 0' is always true and
     * leaves the query site-wide. This is best-effort static scoping; the
     * parameter is still bound server-side from the validated course context.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_courseid_comparison(string $sql): ?string {
        $patterns = [
            '/=\s*:courseid\b/i',
            '/:courseid\s*=/i',
            '/\bin\s*\(\s*:courseid\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return null;
            }
        }
        return 'courseid must be used in an equality filter';
    }

    /**
     * Statements touching {user} must also reference a course-scoped
     * enrolment or activity table.
     *
     * Without such a join the :courseid filter cannot restrict the user rows
     * ('SELECT email FROM {user} WHERE c.id = :courseid' is a site-wide
     * pull), so at least one COURSE_SCOPED_TABLES placeholder is required.
     *
     * @param string $sql Candidate SQL.
     * @return string|null Rejection reason, or null when the check passes.
     */
    public static function check_user_course_scope(string $sql): ?string {
        if (strpos($sql, '{user}') === false) {
            return null;
        }
        foreach (self::COURSE_SCOPED_TABLES as $table) {
            if (strpos($sql, '{' . $table . '}') !== false) {
                return null;
            }
        }
        return 'user data must be joined through course-scoped enrolment or activity tables';
    }
}
