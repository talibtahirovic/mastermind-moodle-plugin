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
 * Privacy Subsystem implementation for block_mastermind_assistant.
 *
 * @package    block_mastermind_assistant
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_mastermind_assistant\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\user_preference_provider;

/**
 * Privacy provider for block_mastermind_assistant.
 *
 * This plugin stores a user preference for AI policy acceptance and sends
 * course data to an external API (Mastermind Dashboard) for AI processing.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Returns metadata about the data this plugin stores and transmits.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored and/or transmitted by this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        // User preference for AI policy acceptance.
        $collection->add_user_preference(
            'mastermind_ai_policy_accepted',
            'privacy:metadata:preference:ai_policy_accepted'
        );

        // Data sent to the external Mastermind Dashboard API.
        $collection->add_external_location_link('mastermind_dashboard', [
            'coursename' => 'privacy:metadata:mastermind_dashboard:coursename',
            'coursedata' => 'privacy:metadata:mastermind_dashboard:coursedata',
            'activityname' => 'privacy:metadata:mastermind_dashboard:activityname',
            'submissioncontent' => 'privacy:metadata:mastermind_dashboard:submissioncontent',
        ], 'privacy:metadata:mastermind_dashboard');

        return $collection;
    }

    /**
     * Export all user preferences for the plugin.
     *
     * @param int $userid The userid of the user whose data is to be exported.
     */
    public static function export_user_preferences(int $userid) {
        $accepted = get_user_preferences('mastermind_ai_policy_accepted', null, $userid);

        if ($accepted !== null) {
            $description = $accepted
                ? get_string('privacy:ai_policy_accepted_yes', 'block_mastermind_assistant')
                : get_string('privacy:ai_policy_accepted_no', 'block_mastermind_assistant');

            writer::export_user_preference(
                'block_mastermind_assistant',
                'mastermind_ai_policy_accepted',
                $accepted,
                $description
            );
        }
    }
}
