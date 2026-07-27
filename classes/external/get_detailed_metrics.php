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
 * External service to get detailed course metrics.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use block_mastermind_assistant\local\scorm_metrics;
use block_mastermind_assistant\local\feedback_metrics;

/**
 * External service class for getting detailed course metrics.
 */
class get_detailed_metrics extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get detailed course metrics.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute($courseid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        // Check capability.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/mastermind_assistant:view', $context);

        // Calculate detailed metrics directly.
        $metrics = self::calculate_detailed_metrics($params['courseid']);

        return [
            'success' => true,
            'metrics' => json_encode($metrics),
        ];
    }

    /**
     * Calculate detailed metrics for a course
     *
     * @param int $courseid
     * @return array
     */
    private static function calculate_detailed_metrics($courseid) {
        global $DB;

        $metrics = [
            // Category 1: Engagement & Participation.
            'enrollment_count' => 0,
            'active_users' => 0,
            'avg_login_frequency' => 0,
            'dropout_point' => 'N/A',

            // Category 2: Progress & Completion.
            'completion_rate' => 0,
            'time_to_completion' => 'N/A',
            'most_completed_section' => 'N/A',
            'least_completed_section' => 'N/A',
            'most_incomplete_activity' => 'N/A',

            // Category 3: Assessment & Learning Outcomes.
            'avg_quiz_score' => 0,
            'most_failed_question' => 'N/A',
            'avg_quiz_attempts' => 0,
            'quiz_performance' => [],
            'assignment_submissions' => [],
            'scorm_performance' => [],
            'avg_scorm_score' => 'N/A',
            'scorm_completion_rate' => 'N/A',
            'weakest_scorm_sco' => 'N/A',

            // Category 4: Satisfaction & Feedback.
            'satisfaction_score' => 'No feedback activity',
            'feedback_summary' => [],

            // Category 5: Retention & Dropout.
            'dropout_rate' => 0,
            'dropout_timing' => 'N/A',

            // Category 6: Interaction & Collaboration.
            'forum_posts_total' => 0,
            'peer_interactions' => 0,
            'teacher_student_interactions' => 0,
        ];

        // Category 1 metrics: Engagement & Participation.

        // Enrollment count.
        $sql = "SELECT COUNT(DISTINCT ue.userid)
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                WHERE e.courseid = ?";
        $metrics['enrollment_count'] = (int) $DB->count_records_sql($sql, [$courseid]);

        // Active users (last 30 days).
        $thirtydaysago = time() - (30 * DAYSECS);
        $sql = "SELECT COUNT(DISTINCT userid)
                FROM {logstore_standard_log}
                WHERE courseid = ? AND timecreated > ?";
        $metrics['active_users'] = (int) $DB->count_records_sql($sql, [$courseid, $thirtydaysago]);

        // Average login frequency (course views per enrolled user, last 365 days).
        if ($metrics['enrollment_count'] > 0) {
            $oneyearago = time() - (365 * DAYSECS);
            $sql = "SELECT COUNT(*) FROM {logstore_standard_log}
                    WHERE courseid = ? AND action = 'viewed' AND target = 'course'
                      AND timecreated > ?";
            $totalviews = (int) $DB->count_records_sql($sql, [$courseid, $oneyearago]);
            $metrics['avg_login_frequency'] = round($totalviews / $metrics['enrollment_count'], 1);
        }

        // Dropout point — most common module type where users last interacted (last 90 days).
        $ninetydaysago = time() - (90 * DAYSECS);
        $sql = "SELECT m.name AS modname, COUNT(*) AS cnt
                FROM {logstore_standard_log} l
                JOIN (
                    SELECT userid, MAX(timecreated) AS lasttime
                    FROM {logstore_standard_log}
                    WHERE courseid = ? AND contextlevel = 70 AND timecreated > ?
                    GROUP BY userid
                ) latest ON latest.userid = l.userid AND latest.lasttime = l.timecreated
                JOIN {course_modules} cm ON cm.id = l.contextinstanceid
                JOIN {modules} m ON m.id = cm.module
                WHERE l.courseid = ? AND l.contextlevel = 70 AND l.timecreated > ?
                GROUP BY m.name
                ORDER BY cnt DESC
                LIMIT 1";
        $dropoutpoint = $DB->get_record_sql($sql, [$courseid, $ninetydaysago, $courseid, $ninetydaysago]);
        if ($dropoutpoint) {
            $metrics['dropout_point'] = $dropoutpoint->modname;
        }

        // Category 2 metrics: Progress & Completion.

        // Course completion rate.
        $completed = $DB->count_records_select(
            'course_completions',
            'course = ? AND timecompleted IS NOT NULL',
            [$courseid]
        );
        if ($metrics['enrollment_count'] > 0) {
            $metrics['completion_rate'] = round(($completed / $metrics['enrollment_count']) * 100, 1);
        }

        // Average time to completion.
        $sql = "SELECT AVG(timecompleted - timestarted) AS avgtime
                FROM {course_completions}
                WHERE course = ? AND timecompleted IS NOT NULL AND timestarted IS NOT NULL AND timestarted > 0";
        $result = $DB->get_record_sql($sql, [$courseid]);
        if ($result && $result->avgtime > 0) {
            $days = round($result->avgtime / 86400);
            $metrics['time_to_completion'] = $days . ' days';
        }

        // Most/least completed section. Sections with no visible modules are
        // excluded — an empty section would otherwise always rank as least
        // completed (0 completions) and skew the recommendations.
        $sql = "SELECT cs.id, cs.section, cs.name, COUNT(DISTINCT cmc.userid) AS completions
                FROM {course_sections} cs
                JOIN {course_modules} cm ON cm.section = cs.id AND cm.visible = 1
                LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.completionstate > 0
                WHERE cs.course = ?
                GROUP BY cs.id, cs.section, cs.name
                ORDER BY completions DESC";
        $sections = $DB->get_records_sql($sql, [$courseid]);
        if ($sections) {
            $first = reset($sections);
            $last = end($sections);
            $metrics['most_completed_section'] = $first->name ?: 'Section ' . $first->section;
            $metrics['least_completed_section'] = $last->name ?: 'Section ' . $last->section;
        }

        // Most incomplete activity.
        $sql = "SELECT cm.id, m.name AS modname, cm.instance
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.completionstate > 0
                WHERE cm.course = ? AND cm.completion > 0 AND cm.visible = 1
                GROUP BY cm.id, m.name, cm.instance
                ORDER BY COUNT(cmc.id) ASC
                LIMIT 1";
        $incomplete = $DB->get_record_sql($sql, [$courseid]);
        if ($incomplete) {
            $metrics['most_incomplete_activity'] = $incomplete->modname;
        }

        // Category 3 metrics: Assessment & Learning Outcomes.

        // Average quiz score.
        $sql = "SELECT AVG(CASE WHEN q.sumgrades > 0 THEN (qa.sumgrades / q.sumgrades) * 100 ELSE 0 END) AS avgscore
                FROM {quiz_attempts} qa
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE q.course = ? AND qa.state = 'finished'";
        $result = $DB->get_record_sql($sql, [$courseid]);
        if ($result && $result->avgscore) {
            $metrics['avg_quiz_score'] = round($result->avgscore, 1);
        }

        // Most failed question — query question_attempts for wrong answers.
        $sql = "SELECT q.id, q.name, COUNT(*) AS failcount
                FROM {question_attempts} qatt
                JOIN {question} q ON q.id = qatt.questionid
                JOIN {quiz_attempts} quiza ON quiza.uniqueid = qatt.questionusageid
                JOIN {quiz} qz ON qz.id = quiza.quiz
                WHERE qz.course = ? AND quiza.state = 'finished'
                  AND qatt.rightanswer != qatt.responsesummary
                  AND qatt.responsesummary IS NOT NULL AND qatt.responsesummary != ''
                GROUP BY q.id, q.name
                ORDER BY failcount DESC
                LIMIT 1";
        $failed = $DB->get_record_sql($sql, [$courseid]);
        if ($failed) {
            $metrics['most_failed_question'] = $failed->name . ' (' . $failed->failcount . ' wrong)';
        }

        // Average quiz attempts.
        $sql = "SELECT AVG(acnt) AS avgattempts
                FROM (
                    SELECT COUNT(*) AS acnt
                    FROM {quiz_attempts} qa
                    JOIN {quiz} q ON q.id = qa.quiz
                    WHERE q.course = ?
                    GROUP BY qa.userid, qa.quiz
                ) t";
        $result = $DB->get_record_sql($sql, [$courseid]);
        if ($result && $result->avgattempts) {
            $metrics['avg_quiz_attempts'] = round($result->avgattempts, 1);
        }

        // Per-quiz performance breakdown.
        $sql = "SELECT q.id, q.name,
                    AVG(CASE WHEN q.sumgrades > 0 THEN (qa.sumgrades / q.sumgrades) * 100 ELSE 0 END) AS avgscore,
                    COUNT(qa.id) AS attempts
                FROM {quiz_attempts} qa
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE q.course = ? AND qa.state = 'finished'
                GROUP BY q.id, q.name";
        $quizzes = $DB->get_records_sql($sql, [$courseid]);
        $metrics['quiz_performance'] = array_values(array_map(function ($q) {
            return ['name' => $q->name, 'avg_score' => round($q->avgscore, 1), 'attempts' => (int) $q->attempts];
        }, $quizzes));

        // Assignment submission rates.
        $sql = "SELECT a.id, a.name,
                    (SELECT COUNT(DISTINCT s.userid) FROM {assign_submission} s
                     WHERE s.assignment = a.id AND s.status = 'submitted') AS submitted
                FROM {assign} a WHERE a.course = ?";
        $assigns = $DB->get_records_sql($sql, [$courseid]);
        $enrolled = $metrics['enrollment_count'];
        $metrics['assignment_submissions'] = array_values(array_map(function ($a) use ($enrolled) {
            $sub = (int) $a->submitted;
            return [
                'name' => $a->name,
                'submitted' => $sub,
                'enrolled' => $enrolled,
                'rate' => $enrolled > 0 ? round($sub / $enrolled * 100, 1) : 0,
            ];
        }, $assigns));

        // SCORM performance: per-package and per-SCO aggregates from the
        // tracking data (empty on courses without SCORM or on pre-4.3 sites).
        $scormdata = scorm_metrics::collect($courseid);
        $metrics['scorm_performance'] = $scormdata['packages'];
        if ($scormdata['avg_scorm_score'] !== null) {
            $metrics['avg_scorm_score'] = $scormdata['avg_scorm_score'];
        }
        if ($scormdata['scorm_completion_rate'] !== null) {
            $metrics['scorm_completion_rate'] = $scormdata['scorm_completion_rate'];
        }
        if ($scormdata['weakest_scorm_sco'] !== null) {
            $metrics['weakest_scorm_sco'] = $scormdata['weakest_scorm_sco'];
        }

        // Category 4 metrics: Satisfaction & Feedback.

        $feedbackdata = feedback_metrics::collect($courseid);
        if ($feedbackdata['satisfaction'] !== null) {
            $metrics['satisfaction_score'] = number_format($feedbackdata['satisfaction'], 1) . '/5';
        }
        $metrics['feedback_summary'] = $feedbackdata['activities'];

        // Category 5 metrics: Retention & Dropout.

        // Dropout rate (enrolled but not active in last 30 days).
        if ($metrics['enrollment_count'] > 0) {
            $metrics['dropout_rate'] = round(
                (($metrics['enrollment_count'] - $metrics['active_users']) / $metrics['enrollment_count']) * 100,
                1
            );
        }

        // Dropout timing — approximate week when inactive users last accessed (last 365 days).
        $course = $DB->get_record('course', ['id' => $courseid], 'startdate');
        if ($course && $course->startdate > 0) {
            $oneyearagodt = time() - (365 * DAYSECS);
            $sql = "SELECT AVG(lastaccess) AS avglast
                    FROM (
                        SELECT MAX(timecreated) AS lastaccess
                        FROM {logstore_standard_log}
                        WHERE courseid = ? AND timecreated > ?
                        GROUP BY userid
                    ) t";
            $result = $DB->get_record_sql($sql, [$courseid, $oneyearagodt]);
            if ($result && $result->avglast > 0) {
                $weeknum = max(1, ceil(($result->avglast - $course->startdate) / 604800));
                $metrics['dropout_timing'] = 'Week ' . $weeknum;
            }
        }

        // Category 6 metrics: Interaction & Collaboration.

        // Forum posts total.
        $sql = "SELECT COUNT(fp.id)
                FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fd.id = fp.discussion
                JOIN {forum} f ON f.id = fd.forum
                WHERE f.course = ?";
        $metrics['forum_posts_total'] = (int) $DB->count_records_sql($sql, [$courseid]);

        // Peer interactions (replies).
        $sql = "SELECT COUNT(fp.id)
                FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fd.id = fp.discussion
                JOIN {forum} f ON f.id = fd.forum
                WHERE f.course = ? AND fp.parent > 0";
        $metrics['peer_interactions'] = (int) $DB->count_records_sql($sql, [$courseid]);

        // Teacher-student interactions (forum posts by users with teacher role).
        $sql = "SELECT COUNT(DISTINCT fp.id)
                FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fd.id = fp.discussion
                JOIN {forum} f ON f.id = fd.forum
                JOIN {role_assignments} ra ON ra.userid = fp.userid
                JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
                JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = f.course
                WHERE f.course = ?";
        $metrics['teacher_student_interactions'] = (int) $DB->count_records_sql($sql, [$courseid]);

        return $metrics;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'metrics' => new external_value(PARAM_RAW, 'Detailed metrics JSON'),
        ]);
    }
}
