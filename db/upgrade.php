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
 * Upgrade steps for Mastermind Assistant
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Upgrade script for Mastermind Assistant block
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_mastermind_assistant_upgrade($oldversion) {
    global $DB;

    // Automatically add block as sticky block on upgrade to v1.0+.
    if ($oldversion < 2025100450) {
        // Check if block already exists as a sticky block (showinsubcontexts = 1)
        // Don't create a duplicate if user already configured one manually.
        $existing = $DB->get_record('block_instances', [
            'blockname' => 'mastermind_assistant',
            'showinsubcontexts' => 1,
        ]);

        if (!$existing) {
            // Also check if there's ANY mastermind_assistant block in system context.
            $systemblock = $DB->get_record('block_instances', [
                'blockname' => 'mastermind_assistant',
                'parentcontextid' => SYSCONTEXTID,
            ]);

            if (!$systemblock) {
                // Create sticky block instance only if no existing sticky or system-level block.
                $blockinstance = new stdClass();
                $blockinstance->blockname = 'mastermind_assistant';
                $blockinstance->parentcontextid = SYSCONTEXTID;
                $blockinstance->showinsubcontexts = 1;
                $blockinstance->pagetypepattern = '*';
                $blockinstance->subpagepattern = null;
                $blockinstance->defaultregion = 'side-pre';
                $blockinstance->defaultweight = 0;
                $blockinstance->configdata = '';
                $blockinstance->timecreated = time();
                $blockinstance->timemodified = time();

                $DB->insert_record('block_instances', $blockinstance);
            }
        }

        upgrade_block_savepoint(true, 2025100450, 'mastermind_assistant');
    }

    if ($oldversion < 2026050602) {
        // dashboard_url is now a constant in api_client::DASHBOARD_URL.
        // Drop the persisted row so admin pages no longer surface a stale value.
        // Sites with $CFG->forced_plugin_settings['block_mastermind_assistant']['dashboard_url']
        // continue to override via standard Moodle mechanisms.
        unset_config('dashboard_url', 'block_mastermind_assistant');

        upgrade_block_savepoint(true, 2026050602, 'mastermind_assistant');
    }

    if ($oldversion < 2026050702) {
        // Sites already running the plugin pre-v3.6 won't have received the
        // post-install nudge. Send it once on upgrade — the helper is idempotent
        // so this is safe even if a fresh install path also runs it.
        if (!\block_mastermind_assistant\local\setup_helper::is_setup_complete()) {
            \block_mastermind_assistant\local\setup_helper::queue_install_notification();
        }

        upgrade_block_savepoint(true, 2026050702, 'mastermind_assistant');
    }

    return true;
}
