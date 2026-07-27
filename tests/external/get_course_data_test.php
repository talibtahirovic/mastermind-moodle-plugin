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
 * Tests for the course data payload's evaluation section.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\external;

/**
 * Tests for the course data payload's evaluation section.
 *
 * @covers \block_mastermind_assistant\external\get_course_data
 * @runTestsInSeparateProcesses
 */
final class get_course_data_test extends \advanced_testcase {

    /**
     * The feedback payload is aggregated and carries no user ids.
     */
    public function test_feedback_payload_is_aggregated_and_anonymous(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $feedback = $gen->create_module('feedback', ['course' => $course->id, 'name' => 'Course evaluation']);
        $itemid = $DB->insert_record('feedback_item', (object) [
            'feedback' => $feedback->id, 'template' => 0, 'name' => 'Overall satisfaction',
            'label' => '', 'presentation' => '', 'typ' => 'numeric', 'hasvalue' => 1,
            'position' => 1, 'required' => 0, 'dependitem' => 0, 'dependvalue' => '', 'options' => '',
        ]);
        $user = $gen->create_user();
        $compid = $DB->insert_record('feedback_completed', (object) [
            'feedback' => $feedback->id, 'userid' => $user->id, 'timemodified' => 1720000000,
            'random_response' => 0, 'anonymous_response' => 2, 'courseid' => 0,
        ]);
        $DB->insert_record('feedback_value', (object) [
            'course_id' => 0, 'item' => $itemid, 'completed' => $compid,
            'tmp_completed' => 0, 'value' => '4',
        ]);

        $result = get_course_data::execute($course->id);
        $data = json_decode($result['data'], true);

        $this->assertArrayHasKey('activities', $data['feedback']);
        $this->assertEqualsWithDelta(4.0, $data['feedback']['satisfaction'], 0.05);
        $this->assertSame('Course evaluation', $data['feedback']['activities'][0]['name']);
        // Anonymized: no userid anywhere in the feedback subtree.
        $this->assertStringNotContainsString('userid', json_encode($data['feedback']));
    }
}
