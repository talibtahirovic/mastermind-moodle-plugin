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
 * External service definitions for Mastermind Assistant
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_mastermind_assistant_get_course_data' => [
        'classname'   => 'block_mastermind_assistant\external\get_course_data',
        'methodname'  => 'execute',
        'description' => 'Get comprehensive course data for recommendations',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_get_ai_recommendations' => [
        'classname'   => 'block_mastermind_assistant\external\get_ai_recommendations',
        'methodname'  => 'execute',
        'description' => 'Get AI-powered course analysis and recommendations',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_get_updated_structure' => [
        'classname'   => 'block_mastermind_assistant\external\get_updated_structure',
        'methodname'  => 'execute',
        'description' => 'Get AI-generated updated course structure',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_get_full_analysis' => [
        'classname'   => 'block_mastermind_assistant\external\get_full_analysis',
        'methodname'  => 'execute',
        'description' => 'Full AI analysis: recommendations + updated structure in one call',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_apply_course_structure' => [
        'classname'   => 'block_mastermind_assistant\external\apply_course_structure',
        'methodname'  => 'execute',
        'description' => 'Apply AI-generated course structure changes to actual course',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_get_detailed_metrics' => [
        'classname'   => 'block_mastermind_assistant\external\get_detailed_metrics',
        'methodname'  => 'execute',
        'description' => 'Get detailed course metrics for comprehensive analysis',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_search_courses' => [
        'classname'   => 'block_mastermind_assistant\external\search_courses',
        'methodname'  => 'execute',
        'description' => 'Search for courses by name',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_create_course_with_ai' => [
        'classname'   => 'block_mastermind_assistant\external\create_course_with_ai',
        'methodname'  => 'execute',
        'description' => 'Create a new course with AI-generated structure',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_create_course_from_document' => [
        'classname'   => 'block_mastermind_assistant\external\create_course_from_document',
        'methodname'  => 'execute',
        'description' => 'Create a new course from an uploaded document (PDF, DOCX, TXT)',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_page_content' => [
        'classname'   => 'block_mastermind_assistant\external\generate_page_content',
        'methodname'  => 'execute',
        'description' => 'Generate page content using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_quiz_questions' => [
        'classname'   => 'block_mastermind_assistant\external\generate_quiz_questions',
        'methodname'  => 'execute',
        'description' => 'Generate quiz questions using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_assignment_instructions' => [
        'classname'   => 'block_mastermind_assistant\external\generate_assignment_instructions',
        'methodname'  => 'execute',
        'description' => 'Generate assignment activity instructions using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_check_ai_policy' => [
        'classname'   => 'block_mastermind_assistant\external\check_ai_policy',
        'methodname'  => 'execute',
        'description' => 'Check if user has accepted AI usage policy',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_save_ai_policy' => [
        'classname'   => 'block_mastermind_assistant\external\save_ai_policy',
        'methodname'  => 'execute',
        'description' => 'Save user AI usage policy acceptance',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_test_connection' => [
        'classname'   => 'block_mastermind_assistant\external\test_connection',
        'methodname'  => 'execute',
        'description' => 'Test connection to Mastermind Dashboard API',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_copy_course' => [
        'classname'   => 'block_mastermind_assistant\external\copy_course',
        'methodname'  => 'execute',
        'description' => 'Copy an existing course using backup/restore',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_create_course_from_structure' => [
        'classname'   => 'block_mastermind_assistant\external\create_course_from_structure',
        'methodname'  => 'execute',
        'description' => 'Create a course from a previously previewed AI-generated structure',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_forum_content' => [
        'classname'   => 'block_mastermind_assistant\external\generate_forum_content',
        'methodname'  => 'execute',
        'description' => 'Generate forum content and discussion topics using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_lesson_content' => [
        'classname'   => 'block_mastermind_assistant\external\generate_lesson_content',
        'methodname'  => 'execute',
        'description' => 'Generate lesson pages and branching content using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_glossary_entries' => [
        'classname'   => 'block_mastermind_assistant\external\generate_glossary_entries',
        'methodname'  => 'execute',
        'description' => 'Generate glossary entries and definitions using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_book_content' => [
        'classname'   => 'block_mastermind_assistant\external\generate_book_content',
        'methodname'  => 'execute',
        'description' => 'Generate book chapters and content using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_url_resource' => [
        'classname'   => 'block_mastermind_assistant\external\generate_url_resource',
        'methodname'  => 'execute',
        'description' => 'Generate URL resource recommendations using AI',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_apply_forum_discussions' => [
        'classname'   => 'block_mastermind_assistant\external\apply_forum_discussions',
        'methodname'  => 'execute',
        'description' => 'Create forum discussions from AI-generated content',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_apply_glossary_entries' => [
        'classname'   => 'block_mastermind_assistant\external\apply_glossary_entries',
        'methodname'  => 'execute',
        'description' => 'Create glossary entries from AI-generated content',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_apply_book_chapters' => [
        'classname'   => 'block_mastermind_assistant\external\apply_book_chapters',
        'methodname'  => 'execute',
        'description' => 'Create book chapters from AI-generated content',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_apply_lesson_pages' => [
        'classname'   => 'block_mastermind_assistant\external\apply_lesson_pages',
        'methodname'  => 'execute',
        'description' => 'Create lesson pages from AI-generated content',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_log_generation_feedback' => [
        'classname'   => 'block_mastermind_assistant\external\log_generation_feedback',
        'methodname'  => 'execute',
        'description' => 'Log user feedback (apply/regenerate/discard) on AI-generated content',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_generate_connect_nonce' => [
        'classname'   => 'block_mastermind_assistant\external\generate_connect_nonce',
        'methodname'  => 'execute',
        'description' => 'Generate a single-use nonce and connect URL for the simplified setup flow',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
    'block_mastermind_assistant_disconnect' => [
        'classname'   => 'block_mastermind_assistant\external\disconnect',
        'methodname'  => 'execute',
        'description' => 'Clear the saved Mastermind API key (admin only)',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
        'capabilities' => 'moodle/site:config',
    ],
    'block_mastermind_assistant_complete_setup' => [
        'classname'   => 'block_mastermind_assistant\external\complete_setup',
        'methodname'  => 'execute',
        'description' => 'Mark the post-install setup banner as dismissed',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
        'capabilities' => 'moodle/site:config',
    ],
];
