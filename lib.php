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

// The legacy `block_mastermind_assistant_before_footer()` callback has been
// migrated to the new \core\hook\output\before_footer_html_generation hook.
// See db/hooks.php and \block_mastermind_assistant\hook_callbacks.

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

        // Inline script: the hook fires after Moodle's AMD bundle is emitted,
        // so $PAGE->requires->js_call_amd would arrive too late. We use the
        // globally-available require() to dynamically pull in core/ajax.
        $html .= "\n<script>\n(function(){\n"
            . "    var attach = function() {\n"
            . "        var btn = document.getElementById('mastermind-setup-dismiss');\n"
            . "        if (!btn || btn.dataset.mmBound) { return; }\n"
            . "        btn.dataset.mmBound = '1';\n"
            . "        btn.addEventListener('click', function() {\n"
            . "            btn.disabled = true;\n"
            . "            require(['core/ajax', 'core/notification'], function(Ajax, Notification) {\n"
            . "                Ajax.call([{\n"
            . "                    methodname: 'block_mastermind_assistant_complete_setup',\n"
            . "                    args: {}\n"
            . "                }])[0].then(function() {\n"
            . "                    var banner = document.getElementById('mastermind-setup-banner');\n"
            . "                    if (banner && banner.parentNode) {\n"
            . "                        banner.parentNode.removeChild(banner);\n"
            . "                    }\n"
            . "                    return null;\n"
            . "                }).catch(function(err) {\n"
            . "                    btn.disabled = false;\n"
            . "                    if (Notification && Notification.exception) { Notification.exception(err); }\n"
            . "                });\n"
            . "            });\n"
            . "        });\n"
            . "    };\n"
            . "    if (document.readyState === 'loading') {\n"
            . "        document.addEventListener('DOMContentLoaded', attach);\n"
            . "    } else {\n"
            . "        attach();\n"
            . "    }\n"
            . "})();\n</script>";

        return $html;
    }
}

/**
 * Add a secondary-navigation node so the assistant is discoverable inside courses.
 *
 * Decision rationale: we use the navigation extension callback instead of
 * modifying $CFG->defaultblocks because the latter is a global override that
 * other plugins routinely clobber on their own install/upgrade. The nav node
 * also surfaces on small viewports where the side-pre region is hidden.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course record.
 * @param context_course $context The course context.
 * @return void
 */
function block_mastermind_assistant_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('block/mastermind_assistant:view', $context)) {
        return;
    }

    $url = new moodle_url('/course/view.php', [
        'id' => $course->id,
        'mastermind' => 1, // hint that the block should auto-open / focus
    ]);

    $node = navigation_node::create(
        get_string('nav_open_assistant', 'block_mastermind_assistant'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'mastermind_assistant',
        new pix_icon('i/marker', '')
    );
    $node->showinflatnavigation = false;

    $navigation->add_node($node);
}
