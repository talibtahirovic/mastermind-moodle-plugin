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
 * Adhoc task that delivers the post-install bell-icon notification.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task wrapper around setup_helper::send_install_notification().
 *
 * The notification cannot be sent from inside the install hook because
 * message-provider user preferences aren't populated at that point — the
 * call would emit a debugging() warning that strict CI environments
 * (moodle-plugin-ci) treat as fatal. Queuing as an adhoc task defers
 * delivery to the next cron run, by which time the messaging subsystem
 * is fully bootstrapped.
 */
class send_install_notification extends \core\task\adhoc_task {
    /**
     * Execute the task.
     */
    public function execute(): void {
        \block_mastermind_assistant\local\setup_helper::send_install_notification();
    }
}
