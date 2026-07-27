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
 * Tests for the evaluation feedback metrics collector.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Tests for the evaluation feedback metrics collector.
 *
 * @covers \block_mastermind_assistant\local\feedback_metrics
 */
final class feedback_metrics_test extends \advanced_testcase {

    /**
     * Insert a feedback item row.
     *
     * @param int $feedbackid Feedback instance id.
     * @param string $typ Item type (numeric, textarea, label, ...).
     * @param string $name Item name (the question text).
     * @param int $position Item position.
     * @return int New item id.
     */
    private function add_item(int $feedbackid, string $typ, string $name, int $position = 1): int {
        global $DB;
        return $DB->insert_record('feedback_item', (object) [
            'feedback' => $feedbackid,
            'template' => 0,
            'name' => $name,
            'label' => '',
            'presentation' => $typ === 'textarea' ? '30|5' : '',
            'typ' => $typ,
            'hasvalue' => 1,
            'position' => $position,
            'required' => 0,
            'dependitem' => 0,
            'dependvalue' => '',
            'options' => '',
        ]);
    }

    /**
     * Insert one completed submission with values for the given items.
     *
     * @param int $feedbackid Feedback instance id.
     * @param int $userid Responding user id.
     * @param array $itemvalues Map of itemid => value string.
     * @return int The feedback_completed id.
     */
    private function add_response(int $feedbackid, int $userid, array $itemvalues): int {
        global $DB;
        $compid = $DB->insert_record('feedback_completed', (object) [
            'feedback' => $feedbackid,
            'userid' => $userid,
            'timemodified' => 1720000000,
            'random_response' => 0,
            'anonymous_response' => 2,
            'courseid' => 0,
        ]);
        foreach ($itemvalues as $itemid => $value) {
            $DB->insert_record('feedback_value', (object) [
                'course_id' => 0,
                'item' => $itemid,
                'completed' => $compid,
                'tmp_completed' => 0,
                'value' => $value,
            ]);
        }
        return $compid;
    }

    /**
     * No feedback activity: empty shape.
     */
    public function test_empty_course_returns_empty_shape(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $result = feedback_metrics::collect($course->id);

        $this->assertSame([], $result['activities']);
        $this->assertNull($result['satisfaction']);
    }

    /**
     * Rated questions aggregate to averages; satisfaction spans all rated values.
     */
    public function test_rated_question_aggregates(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $feedback = $gen->create_module('feedback', ['course' => $course->id, 'name' => 'Course evaluation']);
        $item = $this->add_item($feedback->id, 'numeric', 'Overall satisfaction');
        $u1 = $gen->create_user();
        $u2 = $gen->create_user();
        $u3 = $gen->create_user();
        $this->add_response($feedback->id, $u1->id, [$item => '4']);
        $this->add_response($feedback->id, $u2->id, [$item => '5']);
        $this->add_response($feedback->id, $u3->id, [$item => '3']);

        $result = feedback_metrics::collect($course->id);

        $this->assertCount(1, $result['activities']);
        $activity = $result['activities'][0];
        $this->assertSame('Course evaluation', $activity['name']);
        $this->assertSame(3, $activity['responses']);
        $this->assertCount(1, $activity['questions']);
        $q = $activity['questions'][0];
        $this->assertSame('Overall satisfaction', $q['question']);
        $this->assertSame('numeric', $q['type']);
        $this->assertSame(3, $q['responses']);
        $this->assertEqualsWithDelta(4.0, $q['avg'], 0.05);
        $this->assertEqualsWithDelta(4.0, $result['satisfaction'], 0.05);
    }

    /**
     * Text answers become anonymized comments: newest first, tags stripped,
     * truncated to 300 chars, non-numeric never breaks rated averages.
     */
    public function test_comments_and_text_handling(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $feedback = $gen->create_module('feedback', ['course' => $course->id]);
        $rated = $this->add_item($feedback->id, 'numeric', 'Rating', 1);
        $text = $this->add_item($feedback->id, 'textarea', 'What could we improve?', 2);
        $u1 = $gen->create_user();
        $u2 = $gen->create_user();
        $long = str_repeat('a', 400);
        $this->add_response($feedback->id, $u1->id,
            [$rated => '4', $text => '<p>First comment</p>']);
        $this->add_response($feedback->id, $u2->id,
            [$rated => 'not-a-number', $text => $long]);

        $result = feedback_metrics::collect($course->id);

        $activity = $result['activities'][0];
        // Newest (u2) first; truncated to 300; tags stripped from the older one.
        $this->assertCount(2, $activity['comments']);
        $this->assertSame(300, \core_text::strlen($activity['comments'][0]));
        $this->assertSame('First comment', $activity['comments'][1]);
        // Rated question: 2 responses recorded, avg over the single numeric one.
        $q = $activity['questions'][0];
        $this->assertSame(2, $q['responses']);
        $this->assertEqualsWithDelta(4.0, $q['avg'], 0.05);
        // The textarea question row exists with no average.
        $this->assertNull($activity['questions'][1]['avg']);
    }

    /**
     * Comments cap at 50 per activity; presentation-only items are skipped.
     */
    public function test_comment_cap_and_skipped_types(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $feedback = $gen->create_module('feedback', ['course' => $course->id]);
        $this->add_item($feedback->id, 'label', 'Section header', 1);
        $text = $this->add_item($feedback->id, 'textarea', 'Comments?', 2);
        for ($i = 0; $i < 55; $i++) {
            $user = $gen->create_user();
            $this->add_response($feedback->id, $user->id, [$text => 'Comment number ' . $i]);
        }

        $result = feedback_metrics::collect($course->id);

        $activity = $result['activities'][0];
        $this->assertCount(50, $activity['comments']);
        // Newest first: the last inserted submission leads.
        $this->assertSame('Comment number 54', $activity['comments'][0]);
        // The label item was skipped: only the textarea question remains.
        $this->assertCount(1, $activity['questions']);
        $this->assertSame('Comments?', $activity['questions'][0]['question']);
    }

    /**
     * Multichoicerated values are option indexes mapped to presentation ratings.
     */
    public function test_multichoicerated_maps_indexes_to_ratings(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $feedback = $gen->create_module('feedback', ['course' => $course->id]);
        $itemid = $DB->insert_record('feedback_item', (object) [
            'feedback' => $feedback->id, 'template' => 0, 'name' => 'Rate the course',
            'label' => '', 'presentation' => 'r>>>>>10####Low|20####Medium|30####High<<<<<1',
            'typ' => 'multichoicerated', 'hasvalue' => 1, 'position' => 1,
            'required' => 0, 'dependitem' => 0, 'dependvalue' => '', 'options' => '',
        ]);
        $u1 = $gen->create_user();
        $u2 = $gen->create_user();
        // Stored values are option indexes: 1 -> rating 10, 3 -> rating 30.
        $this->add_response($feedback->id, $u1->id, [$itemid => '1']);
        $this->add_response($feedback->id, $u2->id, [$itemid => '3']);

        $result = feedback_metrics::collect($course->id);

        $q = $result['activities'][0]['questions'][0];
        $this->assertSame(2, $q['responses']);
        $this->assertEqualsWithDelta(20.0, $q['avg'], 0.05);
        $this->assertEqualsWithDelta(20.0, $result['satisfaction'], 0.05);
    }

    /**
     * Comments from multiple text questions merge newest-first per activity.
     */
    public function test_comments_merge_newest_first_across_questions(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $feedback = $gen->create_module('feedback', ['course' => $course->id]);
        $q1 = $this->add_item($feedback->id, 'textarea', 'What worked well?', 1);
        $q2 = $this->add_item($feedback->id, 'textarea', 'What could improve?', 2);
        $ua = $gen->create_user();
        $ub = $gen->create_user();
        $uc = $gen->create_user();
        // Oldest submission answers both questions, then B answers only Q1,
        // then the newest submission C answers only Q2.
        $this->add_response($feedback->id, $ua->id, [$q1 => 'A on Q1', $q2 => 'A on Q2']);
        $this->add_response($feedback->id, $ub->id, [$q1 => 'B on Q1']);
        $this->add_response($feedback->id, $uc->id, [$q2 => 'C on Q2']);

        $result = feedback_metrics::collect($course->id);

        $comments = $result['activities'][0]['comments'];
        $this->assertSame('C on Q2', $comments[0]);
        $this->assertSame('B on Q1', $comments[1]);
        $this->assertSame(['A on Q1', 'A on Q2'], array_slice($comments, 2));
    }
}
