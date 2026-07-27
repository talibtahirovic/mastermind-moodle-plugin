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

namespace block_mastermind_assistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregates SCORM tracking data for a course.
 *
 * Reads the Moodle 4.3+ tracking schema (scorm_attempt / scorm_scoes_value /
 * scorm_element) for both SCORM 1.2 (cmi.core.*) and SCORM 2004 (cmi.score.raw,
 * cmi.completion_status, cmi.success_status) element names. 2004 scaled scores
 * (cmi.score.scaled) are not normalized — packages reporting only scaled
 * scores contribute status data but no score averages. On older sites the
 * tables are absent and collect() returns the empty shape, so callers never
 * need a version check of their own.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_metrics {

    /** @var float A SCO score at or above this raw score counts as passed. */
    const PASS_SCORE = 70.0;

    /** @var string[] Score elements: SCORM 1.2 and SCORM 2004. */
    const SCORE_ELEMENTS = ['cmi.core.score.raw', 'cmi.score.raw'];

    /** @var string[] Status elements: SCORM 1.2 lesson status, SCORM 2004 completion/success. */
    const STATUS_ELEMENTS = ['cmi.core.lesson_status', 'cmi.completion_status', 'cmi.success_status'];

    /**
     * Collect per-package and per-SCO SCORM aggregates for a course.
     *
     * @param int $courseid Course id.
     * @return array ['packages' => [...], 'avg_scorm_score' => float|null,
     *                'scorm_completion_rate' => float|null, 'weakest_scorm_sco' => string|null]
     */
    public static function collect(int $courseid): array {
        global $DB;

        $empty = [
            'packages' => [],
            'avg_scorm_score' => null,
            'scorm_completion_rate' => null,
            'weakest_scorm_sco' => null,
        ];

        if (!$DB->get_manager()->table_exists('scorm_attempt')) {
            return $empty;
        }

        // One pass over the tracking values for the course; aggregate in PHP so
        // the SQL stays portable (the value column is text).
        $sql = "SELECT v.id, s.id AS scormid, s.name AS scormname, a.userid,
                       sc.id AS scoid, sc.title AS scotitle, e.element, v.value
                  FROM {scorm_scoes_value} v
                  JOIN {scorm_attempt} a ON a.id = v.attemptid
                  JOIN {scorm} s ON s.id = a.scormid
                  JOIN {scorm_scoes} sc ON sc.id = v.scoid
                  JOIN {scorm_element} e ON e.id = v.elementid
                 WHERE s.course = ? AND e.element IN ('cmi.core.score.raw', 'cmi.core.lesson_status',
                       'cmi.score.raw', 'cmi.completion_status', 'cmi.success_status')";
        $rows = $DB->get_records_sql($sql, [$courseid]);
        if (!$rows) {
            return $empty;
        }

        // package id => ['name', scoes => [scoid => ['title', scores => [], statuses => [uid => status]]],
        //                users => [uid => [scoid => status]]].
        $packages = [];
        foreach ($rows as $row) {
            if (!isset($packages[$row->scormid])) {
                $packages[$row->scormid] = ['name' => $row->scormname, 'scoes' => [], 'users' => []];
            }
            $pkg =& $packages[$row->scormid];
            if (!isset($pkg['scoes'][$row->scoid])) {
                $pkg['scoes'][$row->scoid] = ['title' => $row->scotitle, 'scores' => [], 'statuses' => []];
            }
            if (in_array($row->element, self::SCORE_ELEMENTS, true)) {
                $pkg['scoes'][$row->scoid]['scores'][] = (float) $row->value;
            } else {
                // Done when 1.2 lesson_status or 2004 success/completion says so.
                // OR-ing per user+SCO means multiple status elements (2004 has
                // two) can never double-count a user.
                $done = in_array($row->value, ['passed', 'completed'], true);
                $pkg['scoes'][$row->scoid]['statuses'][$row->userid] =
                    ($pkg['scoes'][$row->scoid]['statuses'][$row->userid] ?? false) || $done;
                $pkg['users'][$row->userid][$row->scoid] =
                    ($pkg['users'][$row->userid][$row->scoid] ?? false) || $done;
            }
            unset($pkg);
        }

        $result = ['packages' => []];
        $allscores = [];
        $completedusers = 0;
        $attemptedusers = 0;
        $weakest = null;

        foreach ($packages as $pkg) {
            $scoids = array_keys($pkg['scoes']);
            $scoes = [];
            $pkgscores = [];
            foreach ($pkg['scoes'] as $sco) {
                $avg = $sco['scores'] ? array_sum($sco['scores']) / count($sco['scores']) : null;
                $passed = count(array_filter($sco['statuses']));
                $entry = [
                    'title' => $sco['title'],
                    'attempts' => count($sco['statuses']),
                    'avg_score' => $avg !== null ? round($avg, 1) : null,
                    'pass_rate' => $sco['statuses'] ? round($passed / count($sco['statuses']) * 100, 1) : null,
                ];
                $scoes[] = $entry;
                if ($avg !== null) {
                    $pkgscores = array_merge($pkgscores, $sco['scores']);
                    if ($weakest === null || $avg < $weakest['avg']) {
                        $weakest = ['title' => $sco['title'], 'avg' => $avg];
                    }
                }
            }

            // A user completed the package when every SCO carries a
            // passed/completed status for them.
            $pkgcompleted = 0;
            foreach ($pkg['users'] as $statuses) {
                if (count(array_filter($statuses)) === count($scoids)) {
                    $pkgcompleted++;
                }
            }
            $pkgusers = count($pkg['users']);
            $attemptedusers += $pkgusers;
            $completedusers += $pkgcompleted;
            $allscores = array_merge($allscores, $pkgscores);

            $result['packages'][] = [
                'name' => $pkg['name'],
                'attempts' => $pkgusers,
                'avg_score' => $pkgscores ? round(array_sum($pkgscores) / count($pkgscores), 1) : null,
                'completion_rate' => $pkgusers ? round($pkgcompleted / $pkgusers * 100, 1) : null,
                'scoes' => $scoes,
            ];
        }

        $result['avg_scorm_score'] = $allscores
            ? round(array_sum($allscores) / count($allscores), 1) : null;
        $result['scorm_completion_rate'] = $attemptedusers
            ? round($completedusers / $attemptedusers * 100, 1) : null;
        $result['weakest_scorm_sco'] = $weakest
            ? $weakest['title'] . ' (avg ' . round($weakest['avg'], 1) . '%)' : null;

        return $result;
    }
}
