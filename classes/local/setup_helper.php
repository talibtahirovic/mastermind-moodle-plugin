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
 * Coordinates post-install setup state for the plugin.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mastermind_assistant\local;

/**
 * Owns the post-install nudge state.
 *
 * Provides idempotent operations for:
 *  - the setup_complete config flag (admin dismissed the banner / completed setup)
 *  - sending the bell-icon notification to every site admin once per install
 */
class setup_helper {
    /** Plugin component name. */
    public const COMPONENT = 'block_mastermind_assistant';

    /** Config flag: admin completed setup or dismissed the banner. */
    public const FLAG_COMPLETE = 'setup_complete';

    /** Config flag: install notification has been delivered (avoids duplicates). */
    public const FLAG_NOTIFIED = 'setup_notification_sent';

    /**
     * Whether the admin has dismissed the banner / completed setup.
     *
     * @return bool
     */
    public static function is_setup_complete(): bool {
        $value = get_config(self::COMPONENT, self::FLAG_COMPLETE);
        return (string) $value === '1';
    }

    /**
     * Mark setup as complete. Idempotent.
     *
     * @return void
     */
    public static function mark_setup_complete(): void {
        set_config(self::FLAG_COMPLETE, '1', self::COMPONENT);
    }

    /**
     * Reset setup state (used by tests / re-install scenarios).
     *
     * @return void
     */
    public static function reset_setup_state(): void {
        unset_config(self::FLAG_COMPLETE, self::COMPONENT);
        unset_config(self::FLAG_NOTIFIED, self::COMPONENT);
    }

    /**
     * Queue the install-notification adhoc task.
     *
     * Called from `db/install.php` and `db/upgrade.php`. We can't send
     * messages directly from those hooks because the messaging subsystem
     * isn't fully bootstrapped — see send_install_notification() comment
     * for details. Idempotent: subsequent calls are no-ops once the
     * per-install flag is set.
     *
     * @return void
     */
    public static function queue_install_notification(): void {
        if ((string) get_config(self::COMPONENT, self::FLAG_NOTIFIED) === '1') {
            return;
        }

        $task = new \block_mastermind_assistant\task\send_install_notification();
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Send a bell-icon notification to every site admin pointing at the settings page.
     *
     * Should be invoked by the adhoc task — NOT directly from install/upgrade
     * hooks. Calling from inside an install hook emits a debugging() warning
     * because message-provider preferences aren't populated yet at that point,
     * which moodle-plugin-ci treats as fatal during install.
     *
     * Idempotent: subsequent calls are no-ops once the per-install flag is set.
     *
     * @return void
     */
    public static function send_install_notification(): void {
        global $CFG;

        if ((string) get_config(self::COMPONENT, self::FLAG_NOTIFIED) === '1') {
            return;
        }

        // During behat/phpunit util `--install`, plugin install hooks run
        // BEFORE admin defaults are populated, so $CFG->noreplyaddress is
        // not yet set. \core_user::get_noreply_user() reads it directly and
        // emits an "Undefined property" warning, which moodle-plugin-ci
        // treats as a fatal during install. Skip without marking the
        // notified flag so the upgrade backfill can fire it later once
        // site config is fully populated.
        if (empty($CFG->noreplyaddress)) {
            return;
        }

        require_once($CFG->dirroot . '/lib/messagelib.php');

        $admins = get_admins();
        $settingsurl = new \moodle_url('/admin/settings.php', [
            'section' => 'blocksettingmastermind_assistant',
        ]);

        foreach ($admins as $admin) {
            $message = new \core\message\message();
            $message->component = self::COMPONENT;
            $message->name = 'setup_required';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('setup_message_subject', self::COMPONENT);
            $message->fullmessage = get_string('setup_message_body', self::COMPONENT);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '<p>'
                . s(get_string('setup_message_body', self::COMPONENT))
                . '</p>';
            $message->smallmessage = get_string('setup_message_subject', self::COMPONENT);
            $message->notification = 1;
            $message->contexturl = $settingsurl->out(false);
            $message->contexturlname = get_string('setup_banner_cta', self::COMPONENT);

            try {
                message_send($message);
            } catch (\Throwable $e) {
                debugging(
                    'Mastermind setup notification send failed for user '
                    . $admin->id . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        set_config(self::FLAG_NOTIFIED, '1', self::COMPONENT);
    }
}
