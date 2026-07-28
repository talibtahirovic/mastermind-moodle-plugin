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
 * Tests for the AI course creation context normalizer.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Tests for the AI course creation context normalizer.
 *
 * @covers \block_mastermind_assistant\local\course_context
 */
final class course_context_test extends \basic_testcase {
    public function test_from_json_keeps_all_whitelisted_keys(): void {
        $json = json_encode([
            'target_audience' => 'university_students',
            'difficulty' => 'beginner',
            'language' => 'German',
            'learning_outcomes' => 'Size a residential PV system',
            'course_size' => 'compact',
            'activity_emphasis' => 'practice',
            'tone' => 'conversational',
        ]);

        $context = course_context::from_json($json);

        $this->assertSame([
            'target_audience' => 'university_students',
            'difficulty' => 'beginner',
            'language' => 'German',
            'learning_outcomes' => 'Size a residential PV system',
            'course_size' => 'compact',
            'activity_emphasis' => 'practice',
            'tone' => 'conversational',
        ], $context);
    }

    public function test_from_json_returns_empty_for_blank_string(): void {
        $this->assertSame([], course_context::from_json(''));
        $this->assertSame([], course_context::from_json('   '));
    }

    public function test_from_json_returns_empty_for_invalid_json(): void {
        $this->assertSame([], course_context::from_json('not-json{'));
    }

    public function test_from_json_returns_empty_for_non_object_json(): void {
        $this->assertSame([], course_context::from_json('"just a string"'));
        $this->assertSame([], course_context::from_json('42'));
    }

    public function test_from_array_drops_unknown_keys_and_non_strings(): void {
        $context = course_context::from_array([
            'injected' => 'value',
            'difficulty' => 3,
            'language' => ['German'],
            'tone' => 'academic',
        ]);

        $this->assertSame(['tone' => 'academic'], $context);
    }

    public function test_from_array_trims_and_drops_empty_values(): void {
        $context = course_context::from_array([
            'language' => '  German  ',
            'difficulty' => '   ',
        ]);

        $this->assertSame(['language' => 'German'], $context);
    }

    public function test_from_array_caps_value_lengths(): void {
        $context = course_context::from_array([
            'learning_outcomes' => str_repeat('a', 600),
            'language' => str_repeat('b', 150),
        ]);

        $this->assertSame(500, \core_text::strlen($context['learning_outcomes']));
        $this->assertSame(100, \core_text::strlen($context['language']));
    }

    public function test_from_array_returns_empty_when_nothing_usable(): void {
        $this->assertSame([], course_context::from_array(['injected' => 'value']));
    }
}
