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
 * Tests for the SCORM metrics collector.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Tests for the SCORM metrics collector.
 *
 * @covers \block_mastermind_assistant\local\scorm_metrics
 */
final class scorm_metrics_test extends \advanced_testcase {

    /**
     * Insert a launchable SCO row for a SCORM instance.
     *
     * @param int $scormid SCORM instance id.
     * @param string $title SCO title.
     * @return int New SCO id.
     */
    private function add_sco(int $scormid, string $title): int {
        global $DB;
        return $DB->insert_record('scorm_scoes', (object) [
            'scorm' => $scormid,
            'manifest' => 'MANIFEST-1',
            'organization' => 'ORG-1',
            'parent' => '/',
            'identifier' => 'SCO-' . str_replace(' ', '-', $title),
            'launch' => 'index.html',
            'scormtype' => 'sco',
            'title' => $title,
            'sortorder' => 0,
        ]);
    }

    /**
     * Insert one tracked value for a user attempt (Moodle 4.3+ schema).
     *
     * @param int $scormid SCORM instance id.
     * @param int $scoid SCO id.
     * @param int $userid User id.
     * @param string $element Data-model element name.
     * @param string $value Tracked value.
     */
    private function track(int $scormid, int $scoid, int $userid, string $element, string $value): void {
        global $DB;
        $attemptid = $DB->get_field('scorm_attempt', 'id',
            ['scormid' => $scormid, 'userid' => $userid, 'attempt' => 1]);
        if (!$attemptid) {
            $attemptid = $DB->insert_record('scorm_attempt', (object) [
                'scormid' => $scormid, 'userid' => $userid, 'attempt' => 1,
            ]);
        }
        $elementid = $DB->get_field('scorm_element', 'id', ['element' => $element]);
        if (!$elementid) {
            $elementid = $DB->insert_record('scorm_element', (object) ['element' => $element]);
        }
        $DB->insert_record('scorm_scoes_value', (object) [
            'attemptid' => $attemptid,
            'scoid' => $scoid,
            'elementid' => $elementid,
            'value' => $value,
            'timemodified' => 1720000000,
        ]);
    }

    /**
     * A course without SCORM data returns the empty shape.
     */
    public function test_empty_course_returns_empty_shape(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $result = scorm_metrics::collect($course->id);

        $this->assertSame([], $result['packages']);
        $this->assertNull($result['avg_scorm_score']);
        $this->assertNull($result['scorm_completion_rate']);
        $this->assertNull($result['weakest_scorm_sco']);
    }

    /**
     * SCORM 1.2 tracking aggregates per SCO and per package.
     */
    public function test_scorm12_aggregates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $sco1 = $this->add_sco($scorm->id, 'Unit One');
        $sco2 = $this->add_sco($scorm->id, 'Unit Two');

        // User 1 passes both SCOs with 80; user 2 fails SCO 1 with 40.
        $this->track($scorm->id, $sco1, $u1->id, 'cmi.core.score.raw', '80');
        $this->track($scorm->id, $sco1, $u1->id, 'cmi.core.lesson_status', 'passed');
        $this->track($scorm->id, $sco2, $u1->id, 'cmi.core.score.raw', '80');
        $this->track($scorm->id, $sco2, $u1->id, 'cmi.core.lesson_status', 'completed');
        $this->track($scorm->id, $sco1, $u2->id, 'cmi.core.score.raw', '40');
        $this->track($scorm->id, $sco1, $u2->id, 'cmi.core.lesson_status', 'failed');

        $result = scorm_metrics::collect($course->id);

        $this->assertCount(1, $result['packages']);
        $pkg = $result['packages'][0];
        $this->assertSame(2, $pkg['attempts']);
        // Scores: 80, 80, 40 -> 66.7 avg; user 1 completed all SCOs -> 50%.
        $this->assertEqualsWithDelta(66.7, $pkg['avg_score'], 0.05);
        $this->assertEqualsWithDelta(50.0, $pkg['completion_rate'], 0.05);
        $this->assertEqualsWithDelta(66.7, $result['avg_scorm_score'], 0.05);
        $this->assertEqualsWithDelta(50.0, $result['scorm_completion_rate'], 0.05);
        $this->assertStringContainsString('Unit One', $result['weakest_scorm_sco']);

        $byname = [];
        foreach ($pkg['scoes'] as $sco) {
            $byname[$sco['title']] = $sco;
        }
        $this->assertEqualsWithDelta(60.0, $byname['Unit One']['avg_score'], 0.05);
        $this->assertEqualsWithDelta(50.0, $byname['Unit One']['pass_rate'], 0.05);
        $this->assertSame(2, $byname['Unit One']['attempts']);
        $this->assertEqualsWithDelta(80.0, $byname['Unit Two']['avg_score'], 0.05);
        $this->assertEqualsWithDelta(100.0, $byname['Unit Two']['pass_rate'], 0.05);
    }

    /**
     * SCORM 2004 element names produce the same aggregates as 1.2.
     */
    public function test_scorm2004_aggregates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $sco1 = $this->add_sco($scorm->id, 'Unit One');
        $sco2 = $this->add_sco($scorm->id, 'Unit Two');

        $this->track($scorm->id, $sco1, $u1->id, 'cmi.score.raw', '80');
        $this->track($scorm->id, $sco1, $u1->id, 'cmi.success_status', 'passed');
        $this->track($scorm->id, $sco2, $u1->id, 'cmi.score.raw', '80');
        $this->track($scorm->id, $sco2, $u1->id, 'cmi.completion_status', 'completed');
        $this->track($scorm->id, $sco1, $u2->id, 'cmi.score.raw', '40');
        $this->track($scorm->id, $sco1, $u2->id, 'cmi.success_status', 'failed');

        $result = scorm_metrics::collect($course->id);

        $this->assertCount(1, $result['packages']);
        $pkg = $result['packages'][0];
        $this->assertSame(2, $pkg['attempts']);
        $this->assertEqualsWithDelta(66.7, $pkg['avg_score'], 0.05);
        $this->assertEqualsWithDelta(50.0, $pkg['completion_rate'], 0.05);
        $this->assertStringContainsString('Unit One', $result['weakest_scorm_sco']);
    }

    /**
     * A user with both 2004 status elements on one SCO is counted once.
     */
    public function test_mixed_status_elements_no_double_count(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $u1 = $this->getDataGenerator()->create_user();
        $sco1 = $this->add_sco($scorm->id, 'Only Unit');

        $this->track($scorm->id, $sco1, $u1->id, 'cmi.completion_status', 'completed');
        $this->track($scorm->id, $sco1, $u1->id, 'cmi.success_status', 'passed');

        $result = scorm_metrics::collect($course->id);

        $pkg = $result['packages'][0];
        $this->assertSame(1, $pkg['attempts']);
        $this->assertEqualsWithDelta(100.0, $pkg['completion_rate'], 0.05);
        $this->assertSame(1, $pkg['scoes'][0]['attempts']);
        $this->assertEqualsWithDelta(100.0, $pkg['scoes'][0]['pass_rate'], 0.05);
    }

    /**
     * 2004 "failed but completed" counts as done (spec: done-if-either).
     */
    public function test_2004_failed_but_completed_counts_done(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $u1 = $this->getDataGenerator()->create_user();
        $sco1 = $this->add_sco($scorm->id, 'Only Unit');

        $this->track($scorm->id, $sco1, $u1->id, 'cmi.success_status', 'failed');
        $this->track($scorm->id, $sco1, $u1->id, 'cmi.completion_status', 'completed');

        $result = scorm_metrics::collect($course->id);

        $this->assertEqualsWithDelta(100.0, $result['packages'][0]['completion_rate'], 0.05);
    }
}
