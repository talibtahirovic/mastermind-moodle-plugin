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
 * Install script for block_mastermind_assistant
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Post-installation script for the block.
 * Creates a sticky block instance so the block appears site-wide automatically.
 */
function xmldb_block_mastermind_assistant_install() {
    global $DB, $CFG;

    // Create sticky block instance so the block appears on all pages automatically.
    $existing = $DB->get_record('block_instances', [
        'blockname' => 'mastermind_assistant',
        'showinsubcontexts' => 1,
    ]);

    if (!$existing) {
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

    // Best-effort install ping. Used to track total plugin installations across
    // all distribution channels (Moodle.org, direct download, etc.). Failure is
    // intentionally silent — the plugin must install cleanly even when the
    // dashboard is unreachable or the site has no outbound network access.
    block_mastermind_assistant_send_install_ping($CFG);

    // Persistent post-install nudge: bell-icon notification to every site admin.
    // The yellow admin-page banner is rendered by lib.php (Task 5) until the
    // admin dismisses it. setup_helper is idempotent — safe on re-runs.
    \block_mastermind_assistant\local\setup_helper::send_install_notification();

    return true;
}

/**
 * Send a non-blocking install ping to the dashboard.
 *
 * Uses Moodle's curl wrapper with a short connection/response timeout so a
 * slow or unreachable dashboard cannot hold up plugin installation. All
 * exceptions are swallowed: the install must succeed regardless.
 *
 * @param object $cfg The Moodle $CFG global.
 * @return void
 */
function block_mastermind_assistant_send_install_ping($cfg): void {
    global $CFG;

    require_once($CFG->libdir . '/filelib.php');

    try {
        $payload = json_encode([
            'lms_url' => $cfg->wwwroot ?? '',
            'plugin_version' => '2026041801',
        ]);

        $curl = new \curl();
        $curl->setHeader('Content-Type: application/json');
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => 2,
            'CURLOPT_TIMEOUT' => 3,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);
        $curl->post('https://mastermindassistant.ai/api/plugin-install', $payload);
    } catch (\Throwable $e) {
        // Silently ignore — plugin install must never fail because of telemetry.
        debugging('Mastermind install ping failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}
