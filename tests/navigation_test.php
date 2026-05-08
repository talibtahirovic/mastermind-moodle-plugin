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
 * Tests for the course navigation extension callback.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/mastermind_assistant/lib.php');

/**
 * Tests for the course navigation extension callback.
 *
 * @covers ::block_mastermind_assistant_extend_navigation_course
 */
final class navigation_test extends \advanced_testcase {
    public function test_node_added_for_users_with_view(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $context = \context_course::instance($course->id);
        $navigation = \navigation_node::create('root');

        block_mastermind_assistant_extend_navigation_course($navigation, $course, $context);

        $node = $navigation->find('mastermind_assistant', \navigation_node::TYPE_CUSTOM);
        $this->assertNotFalse($node);
        $this->assertEquals(
            get_string('nav_open_assistant', 'block_mastermind_assistant'),
            (string) $node->get_content()
        );
    }

    public function test_node_uses_course_view_url_with_mastermind_param(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $context = \context_course::instance($course->id);
        $navigation = \navigation_node::create('root');

        block_mastermind_assistant_extend_navigation_course($navigation, $course, $context);

        $node = $navigation->find('mastermind_assistant', \navigation_node::TYPE_CUSTOM);
        $this->assertNotFalse($node);

        $url = $node->action;
        $this->assertNotNull($url);
        $this->assertStringEndsWith('/course/view.php', $url->get_path());
        $this->assertEquals((string) $course->id, $url->get_param('id'));
        $this->assertEquals('1', $url->get_param('mastermind'));
    }

    public function test_node_omitted_for_users_without_view(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        // Strip the view capability from the student role at the course context.
        $context = \context_course::instance($course->id);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability(
            'block/mastermind_assistant:view',
            CAP_PROHIBIT,
            $studentroleid,
            $context,
            true
        );

        $navigation = \navigation_node::create('root');

        block_mastermind_assistant_extend_navigation_course($navigation, $course, $context);

        $this->assertFalse($navigation->find('mastermind_assistant', \navigation_node::TYPE_CUSTOM));
    }
}
