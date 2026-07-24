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
 * External function to get course data
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
use external_multiple_structure;
use external_value;
use context_course;
use Exception;
use moodle_exception;
use block_mastermind_assistant\local\scorm_metrics;

/**
 * External API for get course data.
 */
class get_course_data extends external_api {
    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get comprehensive course data
     * @param int $courseid
     * @return array
     */
    public static function execute($courseid) {
        global $DB, $CFG;

        try {
            // Validate parameters.
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
            ]);

            // Debug and fix potential section ID vs course ID confusion.
            $courseexists = $DB->record_exists('course', ['id' => $params['courseid']]);
            if (!$courseexists) {
                // Check if the provided ID is actually a section ID.
                $sectionrecord = $DB->get_record('course_sections', ['id' => $params['courseid']], 'id, course, section');
                if ($sectionrecord) {
                    $params['courseid'] = $sectionrecord->course;
                }
            }

            // Check course access.
            $course = get_course($params['courseid']);
            $context = context_course::instance($params['courseid']);
            self::validate_context($context);

            // Check capability.
            require_capability('block/mastermind_assistant:view', $context);

            // Gather comprehensive course data.
            $data = self::gather_course_data($params['courseid']);

            return [
                'success' => true,
                'data' => json_encode($data),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => json_encode(['error' => $e->getMessage()]),
            ];
        }
    }

    /**
     * Returns description of method result value
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'data' => new external_value(PARAM_RAW, 'Course data as JSON string'),
        ]);
    }

    /**
     * Gather comprehensive course data including sections and activities
     *
     * @param int $courseid
     * @return array
     */
    protected static function gather_course_data($courseid) {
        global $DB, $CFG;

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);

        $data = [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'summary' => strip_tags($course->summary),
                'format' => $course->format,
                'startdate' => $course->startdate,
                'enddate' => $course->enddate,
                'visible' => $course->visible,
                'category' => $course->category,
                'numsections' => $course->numsections,
                'timecreated' => $course->timecreated,
                'timemodified' => $course->timemodified,
            ],
            'sections' => [],
            'activities' => [],
            'users' => [],
            'grades' => [],
            'completions' => [],
            'forum_posts' => [],
            'feedback' => [],
        ];

        // Build a map of activities grouped by section number.
        $activitiesbysection = [];
        foreach ($modinfo->get_cms() as $cm) {
            $moduledata = [];

            // Add module-specific data.
            if ($cm->modname === 'forum') {
                $forum = $DB->get_record('forum', ['id' => $cm->instance]);
                if ($forum) {
                    $moduledata = [
                        'type' => $forum->type,
                        'intro' => strip_tags($forum->intro),
                        'assessed' => $forum->assessed,
                        'scale' => $forum->scale,
                    ];
                }
            } else if ($cm->modname === 'assign') {
                $assignment = $DB->get_record('assign', ['id' => $cm->instance]);
                if ($assignment) {
                    $moduledata = [
                        'intro' => strip_tags($assignment->intro ?? ''),
                        'duedate' => $assignment->duedate ?? 0,
                        'allowsubmissionsfromdate' => $assignment->allowsubmissionsfromdate ?? 0,
                        'grade' => $assignment->grade ?? 0,
                        'maxattempts' => $assignment->maxattempts ?? 0,
                    ];
                }
            } else if ($cm->modname === 'quiz') {
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
                if ($quiz) {
                    $moduledata = [
                        'intro' => strip_tags($quiz->intro ?? ''),
                        'timeopen' => $quiz->timeopen ?? 0,
                        'timeclose' => $quiz->timeclose ?? 0,
                        'timelimit' => $quiz->timelimit ?? 0,
                        'attempts' => $quiz->attempts ?? 0,
                        'grade' => $quiz->grade ?? 0,
                        'questions' => $quiz->questions ?? '',
                    ];
                }
            }

            $activityentry = [
                'id' => $cm->id,
                'module' => $cm->modname,
                'name' => $cm->name,
                'section' => $cm->sectionnum,
                'visible' => $cm->visible,
                'completion' => $cm->completion,
                'completionexpected' => $cm->completionexpected,
                'added' => $cm->added,
                'indent' => $cm->indent,
                'url' => $cm->url ? $cm->url->out() : '',
                'module_data' => $moduledata,
            ];

            $activitiesbysection[$cm->sectionnum][] = $activityentry;
            // Also keep in the flat list for backward compatibility.
            $data['activities'][] = $activityentry;
        }

        // Get course sections — nest activities inside each section.
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section');
        foreach ($sections as $section) {
            $data['sections'][] = [
                'id' => $section->id,
                'section' => $section->section,
                'name' => $section->name ?: '',
                'summary' => strip_tags($section->summary),
                'sequence' => $section->sequence ?: '',
                'visible' => $section->visible,
                'timemodified' => $section->timemodified,
                'activities' => $activitiesbysection[$section->section] ?? [],
            ];
        }

        // Get enrolled users.
        $users = get_enrolled_users(context_course::instance($courseid));
        foreach ($users as $user) {
            $data['users'][] = [
                'id' => $user->id,
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'lastaccess' => $user->lastaccess,
                'firstaccess' => $user->firstaccess,
                'timeenrolled' => $user->timeenrolled ?? 0,
            ];
        }

        // Get grade data and encode as JSON for simplicity.
        $grades = [];
        $gradeitems = $DB->get_records('grade_items', ['courseid' => $courseid]);

        // Bulk-load all grade_grades for these items in a single query.
        $allitemgrades = [];
        if ($gradeitems) {
            $itemids = array_keys($gradeitems);
            [$insql, $inparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
            $graderecords = $DB->get_records_select('grade_grades', "itemid $insql", $inparams);
            foreach ($graderecords as $grade) {
                $allitemgrades[$grade->itemid][] = $grade;
            }
        }

        foreach ($gradeitems as $gradeitem) {
            $gradedata = [
                'item' => [
                    'id' => $gradeitem->id,
                    'itemname' => $gradeitem->itemname,
                    'itemtype' => $gradeitem->itemtype,
                    'itemmodule' => $gradeitem->itemmodule,
                    'grademax' => $gradeitem->grademax,
                    'grademin' => $gradeitem->grademin,
                    'gradepass' => $gradeitem->gradepass,
                ],
                'grades' => [],
            ];

            $itemgrades = $allitemgrades[$gradeitem->id] ?? [];
            foreach ($itemgrades as $grade) {
                $gradedata['grades'][] = [
                    'userid' => $grade->userid,
                    'rawgrade' => $grade->rawgrade,
                    'finalgrade' => $grade->finalgrade,
                    'timecreated' => $grade->timecreated,
                    'timemodified' => $grade->timemodified,
                ];
            }

            $grades[] = $gradedata;
        }
        $data['grades'] = $grades;

        // Get completion data.
        $completions = [];
        if (!empty($modinfo->get_cms())) {
            $cmids = array_keys($modinfo->get_cms());
            [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $completionrecords = $DB->get_records_select(
                'course_modules_completion',
                "coursemoduleid $insql",
                $inparams
            );
            foreach ($completionrecords as $completion) {
                $completions[] = [
                    'coursemoduleid' => $completion->coursemoduleid,
                    'userid' => $completion->userid,
                    'completionstate' => $completion->completionstate,
                    'viewed' => $completion->viewed,
                    'timemodified' => $completion->timemodified,
                ];
            }
        }
        $data['completions'] = $completions;

        // Get forum posts.
        $forumposts = [];
        $forumpostrecords = $DB->get_records_sql("
            SELECT fp.*, fd.course
            FROM {forum_posts} fp
            JOIN {forum_discussions} fd ON fd.id = fp.discussion
            WHERE fd.course = ?
            ORDER BY fp.created DESC
            LIMIT 100
        ", [$courseid]);

        foreach ($forumpostrecords as $post) {
            $forumposts[] = [
                'id' => $post->id,
                'discussion' => $post->discussion,
                'parent' => $post->parent,
                'userid' => $post->userid,
                'created' => $post->created,
                'modified' => $post->modified,
                'subject' => $post->subject,
                'message' => strip_tags($post->message),
                'totalscore' => $post->totalscore ?? 0,
            ];
        }
        $data['forum_posts'] = $forumposts;

        // Get feedback data.
        $feedbackdata = [];
        $feedbacks = $DB->get_records('feedback', ['course' => $courseid]);
        foreach ($feedbacks as $feedback) {
            $fbdata = [
                'feedback' => [
                    'id' => $feedback->id,
                    'name' => $feedback->name,
                    'intro' => strip_tags($feedback->intro),
                    'timeopen' => $feedback->timeopen,
                    'timeclose' => $feedback->timeclose,
                ],
                'items' => [],
                'values' => [],
            ];

            // Get feedback items.
            $items = $DB->get_records('feedback_item', ['feedback' => $feedback->id]);
            foreach ($items as $item) {
                $fbdata['items'][] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'label' => $item->label,
                    'typ' => $item->typ,
                    'presentation' => $item->presentation,
                    'required' => $item->required,
                ];
            }

            // Get feedback values.
            $values = $DB->get_records_sql("
                SELECT fv.*, fi.name as itemname, fi.typ
                FROM {feedback_value} fv
                JOIN {feedback_item} fi ON fi.id = fv.item
                WHERE fi.feedback = ?
            ", [$feedback->id]);

            foreach ($values as $value) {
                $fbdata['values'][] = [
                    'id' => $value->id,
                    'item' => $value->item,
                    'completed' => $value->completed,
                    'userid' => $value->userid,
                    'value' => $value->value,
                    'itemname' => $value->itemname,
                    'itemtype' => $value->typ,
                ];
            }

            $feedbackdata[] = $fbdata;
        }
        $data['feedback'] = $feedbackdata;

        // Audit flags — help AI detect post-copy issues.
        $auditflags = [];

        // Past due dates on assignments and quizzes.
        $now = time();
        $pastdue = $DB->get_records_sql(
            "SELECT cm.id, a.name, a.duedate FROM {assign} a
             JOIN {course_modules} cm ON cm.instance = a.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
             WHERE cm.course = ? AND a.duedate > 0 AND a.duedate < ?",
            [$courseid, $now]
        );
        if ($pastdue) {
            $auditflags['past_due_dates'] = array_values(array_map(function ($a) {
                return ['name' => $a->name, 'duedate' => userdate($a->duedate)];
            }, $pastdue));
        }

        // Old year references in page content.
        $currentyear = (int) date('Y');
        $oldyears = [$currentyear - 1, $currentyear - 2, $currentyear - 3];
        $yearlike = [];
        $yearparams = ['cid_yr' => $courseid];
        foreach ($oldyears as $i => $yr) {
            $paramname = 'yr' . $i;
            $yearlike[] = $DB->sql_like('p.content', ':' . $paramname, false);
            $yearparams[$paramname] = '%' . $yr . '%';
        }
        $pagesold = $DB->get_records_sql(
            "SELECT p.id, p.name FROM {page} p
             JOIN {course_modules} cm ON cm.instance = p.id
             JOIN {modules} m ON m.id = cm.module AND m.name = 'page'
             WHERE cm.course = :cid_yr AND (" . implode(' OR ', $yearlike) . ")",
            $yearparams
        );
        if ($pagesold) {
            $auditflags['old_year_references'] = array_values(array_map(function ($p) {
                return $p->name;
            }, $pagesold));
        }

        // Empty sections (no activities).
        $emptysections = $DB->get_records_sql(
            "SELECT cs.id, cs.section, cs.name FROM {course_sections} cs
             WHERE cs.course = ? AND (cs.sequence IS NULL OR cs.sequence = '')",
            [$courseid]
        );
        if ($emptysections) {
            $auditflags['empty_sections'] = array_values(array_map(function ($s) {
                return $s->name ?: 'Section ' . $s->section;
            }, $emptysections));
        }

        // Missing enrollments.
        $enrollcount = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid
             WHERE e.courseid = ?",
            [$courseid]
        );
        if ($enrollcount <= 1) {
            $auditflags['missing_enrollments'] = true;
        }

        $data['audit_flags'] = $auditflags;

        // SCORM performance: per-package and per-SCO tracking aggregates, so
        // the AI analysis can reason about SCORM content like any other
        // activity (empty on courses without SCORM data).
        $scormdata = scorm_metrics::collect($courseid);
        $data['scorm_performance'] = $scormdata['packages'];
        if ($scormdata['weakest_scorm_sco'] !== null) {
            $data['scorm_summary'] = [
                'avg_score' => $scormdata['avg_scorm_score'],
                'completion_rate' => $scormdata['scorm_completion_rate'],
                'weakest_sco' => $scormdata['weakest_scorm_sco'],
            ];
        }

        return $data;
    }
}
