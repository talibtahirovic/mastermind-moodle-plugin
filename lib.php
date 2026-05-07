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
 * Plugin hook callbacks for Mastermind Assistant.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Inject the post-install setup banner on admin pages.
 *
 * Hook fires on every page render. Returns empty string when the banner
 * should not show; an HTML string otherwise.
 *
 * @return string HTML to inject into the page footer, or '' to skip.
 */
function block_mastermind_assistant_before_footer(): string {
    global $PAGE, $USER;

    if (!function_exists('block_mastermind_assistant_render_setup_banner')) {
        // Defensive: shouldn't happen — same file is being parsed.
        return '';
    }

    return block_mastermind_assistant_render_setup_banner($PAGE, $USER);
}

if (!function_exists('block_mastermind_assistant_render_setup_banner')) {
    /**
     * Render the setup banner HTML for the given page + user.
     *
     * Extracted from the hook so it's unit-testable (can pass mock $PAGE/$USER).
     *
     * @param mixed $page The Moodle $PAGE object (or stub with ->pagelayout).
     * @param mixed $user The current user (or stub with ->id).
     * @return string HTML or empty string.
     */
    function block_mastermind_assistant_render_setup_banner($page, $user): string {
        if (during_initial_install() || empty($user->id) || isguestuser($user)) {
            return '';
        }

        if (\block_mastermind_assistant\local\setup_helper::is_setup_complete()) {
            return '';
        }

        $context = \context_system::instance();
        if (!has_capability('moodle/site:config', $context)) {
            return '';
        }

        $allowedlayouts = ['admin', 'mydashboard', 'frontpage'];
        if (!in_array($page->pagelayout ?? '', $allowedlayouts, true)) {
            return '';
        }

        $settingsurl = new \moodle_url('/admin/settings.php', [
            'section' => 'blocksettingmastermind_assistant',
        ]);

        $html = '<div id="mastermind-setup-banner" class="alert alert-warning"'
            . ' style="margin:1rem 0;display:flex;align-items:center;justify-content:space-between;gap:1rem;">'
            . '<div>'
            . '<strong>' . s(get_string('setup_banner_title', 'block_mastermind_assistant')) . '</strong> '
            . s(get_string('setup_banner_body', 'block_mastermind_assistant'))
            . '</div>'
            . '<div style="white-space:nowrap;">'
            . '<a class="btn btn-primary btn-sm" href="' . $settingsurl->out(false) . '">'
            . s(get_string('setup_banner_cta', 'block_mastermind_assistant'))
            . '</a> '
            . '<button type="button" class="btn btn-link btn-sm" id="mastermind-setup-dismiss">'
            . s(get_string('setup_banner_dismiss', 'block_mastermind_assistant'))
            . '</button>'
            . '</div>'
            . '</div>';

        if (method_exists($page, 'requires') && $page->requires) {
            $page->requires->js_call_amd('block_mastermind_assistant/setup_banner', 'init');
        }

        return $html;
    }
}
