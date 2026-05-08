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
 * Hook callback handlers for Mastermind Assistant.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant;

/**
 * Hook callbacks dispatched by Moodle's \core\hook\* system.
 */
class hook_callbacks {
    /**
     * Inject the post-install setup banner on admin pages.
     *
     * Replaces the legacy `block_mastermind_assistant_before_footer()` callback
     * that Moodle 4.4+ deprecated in favour of the hook system.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $CFG, $PAGE, $USER;

        // The renderer lives in lib.php (top-level helper, not autoloaded).
        require_once($CFG->dirroot . '/blocks/mastermind_assistant/lib.php');

        if (!function_exists('block_mastermind_assistant_render_setup_banner')) {
            return;
        }

        $html = block_mastermind_assistant_render_setup_banner($PAGE, $USER);
        if ($html !== '') {
            $hook->add_html($html);
        }
    }
}
