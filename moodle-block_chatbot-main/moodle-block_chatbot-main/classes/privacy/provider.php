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
* Privacy provider for block_chatbot
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
namespace block_chatbot\privacy;
 
defined('MOODLE_INTERNAL') || die();
 
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
 
/**
* Privacy provider for block_chatbot
*/
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
 
    /**
     * Get metadata about this plugin's privacy policy
     *
     * @param collection $collection The collection to add metadata to
     * @return collection The updated collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference('block_chatbot_token', 'privacy:metadata:preference:token');
 
        $collection->add_external_location_link(
            'botman',
            [
                'message' => 'privacy:metadata:botman:message',
                'userid' => 'privacy:metadata:botman:userid'
            ],
            'privacy:metadata:botman'
        );
 
        return $collection;
    }
 
    /**
     * Get the list of contexts that contain user information for the specified user
     *
     * @param int $userid The user to search for
     * @return contextlist The list of contexts containing user info for the user
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }
 
    /**
     * Export all user preferences for the plugin
     *
     * @param int $userid The userid of the user whose data is to be exported
     */
    public static function export_user_preferences(int $userid) {
        $token = get_user_preferences('block_chatbot_token', null, $userid);
        if ($token !== null) {
            writer::export_user_preference(
                'block_chatbot',
                'block_chatbot_token',
                $token,
                get_string('privacy:metadata:preference:token', 'block_chatbot')
            );
        }
    }
 
    /**
     * Export all user data for the specified user, in the specified contexts
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        // If the user has data, then only the system context should be present
        $contexts = $contextlist->get_contexts();
        if (count($contexts) == 0) {
            return;
        }
 
        // System context only
        $context = \context_system::instance();
        if (!in_array($context, $contexts)) {
            return;
        }
 
        $userid = $contextlist->get_user()->id;
 
        // Export user preferences
        static::export_user_preferences($userid);
    }
 
    /**
     * Delete all personal data for all users in the specified context
     *
     * @param \context $context Context to delete data from
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        // Only the system context contains user data
        if (!$context instanceof \context_system) {
            return;
        }
 
        // No persistent data to delete
    }
 
    /**
     * Delete all user data for the specified user, in the specified contexts
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        // If the user has data, then only the system context should be present
        $contexts = $contextlist->get_contexts();
        if (count($contexts) == 0) {
            return;
        }
 
        // System context only
        $context = \context_system::instance();
        if (!in_array($context, $contexts)) {
            return;
        }
 
        $userid = $contextlist->get_user()->id;
 
        // Delete user preferences
        unset_user_preference('block_chatbot_token', $userid);
    }
 
    /**
     * Get the list of users who have data within a context
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
 
        // Only the system context contains user data
        if (!$context instanceof \context_system) {
            return;
        }
 
        // Add users who have the token preference
        $sql = "SELECT userid
                  FROM {user_preferences}
                 WHERE name = :name";
        $params = ['name' => 'block_chatbot_token'];
 
        $userlist->add_from_sql('userid', $sql, $params);
    }
 
    /**
     * Delete multiple users within a single context
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
 
        // Only the system context contains user data
        if (!$context instanceof \context_system) {
            return;
        }
 
        // Delete user preferences
        foreach ($userlist->get_userids() as $userid) {
            unset_user_preference('block_chatbot_token', $userid);
        }
    }
}