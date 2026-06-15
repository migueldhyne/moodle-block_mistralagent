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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_mistralagent\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for block_mistralagent.
 *
 * Personal data stored
 * ────────────────────
 * block_mistralagent_convs  – one row per conversation (userid, courseid, …)
 * block_mistralagent_msgs   – one row per message (content, role, timestamps)
 * block_mistralagent_quotas  – one row per user x course (usage counters)
 *
 * External data
 * ─────────────
 * Message content is sent to the Mistral AI API (mistral.ai) for inference.
 * No data is stored permanently by Mistral beyond their own retention policy.
 *
 * Preset configuration (block instance config) is stored per block instance,
 * not per user, and therefore contains no personal data.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    // Section separator.
    // Metadata.
    // Section separator.

    /**
     * Declare all personal data stored or transmitted by this plugin.
     *
     * Every column that can be linked to a user must be listed here.
     * Omitting a column is a GDPR compliance gap.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        // Conversations table.
        $collection->add_database_table(
            'block_mistralagent_convs',
            [
                'userid'       => 'privacy:metadata:block_mistralagent_convs:userid',
                'agentid'      => 'privacy:metadata:block_mistralagent_convs:agentid',
                'title'        => 'privacy:metadata:block_mistralagent_convs:title',
                'timecreated'  => 'privacy:metadata:block_mistralagent_convs:timecreated',
                'timemodified' => 'privacy:metadata:block_mistralagent_convs:timemodified',
            ],
            'privacy:metadata:block_mistralagent_convs'
        );

        // Messages table.
        $collection->add_database_table(
            'block_mistralagent_msgs',
            [
                'content'     => 'privacy:metadata:block_mistralagent_msgs:content',
                'role'        => 'privacy:metadata:block_mistralagent_msgs:role',
                'timecreated' => 'privacy:metadata:block_mistralagent_msgs:timecreated',
            ],
            'privacy:metadata:block_mistralagent_msgs'
        );

        /*
         * Quotas table. All counters are personal data: they reveal when and how intensively a specific user
         * interacted with the AI assistant.
         */
        $collection->add_database_table(
            'block_mistralagent_quotas',
            [
                'userid'         => 'privacy:metadata:block_mistralagent_quotas:userid',
                'messages_used'  => 'privacy:metadata:block_mistralagent_quotas:messages_used',
                'messages_limit' => 'privacy:metadata:block_mistralagent_quotas:messages_limit',
                'period_start'   => 'privacy:metadata:block_mistralagent_quotas:period_start',
                'timemodified'   => 'privacy:metadata:block_mistralagent_quotas:timemodified',
            ],
            'privacy:metadata:block_mistralagent_quotas'
        );

        // External service: Mistral AI API.
        $collection->add_external_location_link(
            'mistral',
            [
                'messages' => 'privacy:metadata:externalsystem:messages',
            ],
            'privacy:metadata:externalsystem'
        );

        return $collection;
    }

    // Section separator.
    // Context discovery.
    // Section separator.

    /**
     * Return the list of contexts that contain personal data for the given user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {block_mistralagent_convs} c ON c.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND c.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid'       => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Return the list of users who have data in the given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT DISTINCT userid
                  FROM {block_mistralagent_convs}
                 WHERE courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    // Section separator.
    // Export.
    // Section separator.

    /**
     * Export all personal data for the user in the given contexts.
     *
     * Each conversation is exported as a structured object containing the
     * conversation metadata and its messages.  Quota data is exported
     * separately under a "Quota" sub-path.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            // Export conversations and their messages.
            $conversations = $DB->get_records(
                'block_mistralagent_convs',
                ['userid' => $userid, 'courseid' => $courseid]
            );

            foreach ($conversations as $conv) {
                $messages = $DB->get_records(
                    'block_mistralagent_msgs',
                    ['conversationid' => $conv->id],
                    'timecreated ASC'
                );

                $data = (object)[
                    'conversation_id' => $conv->id,
                    'title'           => $conv->title ?? '',
                    'created'         => userdate($conv->timecreated),
                    'modified'        => userdate($conv->timemodified),
                    'messages'        => array_map(static function ($msg) {
                        return [
                            'role'    => $msg->role,
                            'content' => $msg->content,
                            'time'    => userdate($msg->timecreated),
                        ];
                    }, $messages),
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_mistralagent'), 'Conversation ' . $conv->id],
                    $data
                );
            }

            // Export quota data.
            $quota = $DB->get_record(
                'block_mistralagent_quotas',
                ['userid' => $userid, 'courseid' => $courseid]
            );

            if ($quota) {
                $quotadata = (object)[
                    'messages_used'  => $quota->messages_used,
                    'messages_limit' => $quota->messages_limit ?? get_string('unlimited', 'block_mistralagent'),
                    'period_start'   => userdate($quota->period_start),
                    'last_modified'  => userdate($quota->timemodified),
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_mistralagent'), get_string('privacy:quota_path', 'block_mistralagent')],
                    $quotadata
                );
            }
        }
    }

    // Section separator.
    // Deletion.
    // Section separator.

    /**
     * Delete all personal data for all users in the given context.
     *
     * Called when a course is deleted or reset.
     * Messages must be deleted before conversations (foreign key constraint).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $courseid = $context->instanceid;

        // Delete messages first (FK: conversationid → block_mistralagent_convs).
        $conversations = $DB->get_records(
            'block_mistralagent_convs',
            ['courseid' => $courseid],
            '',
            'id'
        );

        foreach ($conversations as $conv) {
            $DB->delete_records('block_mistralagent_msgs', ['conversationid' => $conv->id]);
        }

        $DB->delete_records('block_mistralagent_convs',   ['courseid' => $courseid]);
        $DB->delete_records('block_mistralagent_quotas',  ['courseid' => $courseid]);
    }

    /**
     * Delete all personal data for a specific user in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            $conversations = $DB->get_records(
                'block_mistralagent_convs',
                ['userid' => $userid, 'courseid' => $courseid],
                '',
                'id'
            );

            foreach ($conversations as $conv) {
                $DB->delete_records('block_mistralagent_msgs', ['conversationid' => $conv->id]);
            }

            $DB->delete_records('block_mistralagent_convs',  ['userid' => $userid, 'courseid' => $courseid]);
            $DB->delete_records('block_mistralagent_quotas', ['userid' => $userid, 'courseid' => $courseid]);
        }
    }

    /**
     * Delete personal data for a list of users in the given context.
     *
     * Uses a single bulk DELETE per table via get_in_or_equal() to avoid
     * issuing one query per user.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        $courseid = $context->instanceid;

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = array_merge($userparams, ['courseid' => $courseid]);

        // Retrieve conversation IDs for the bulk message deletion.
        $conversations = $DB->get_records_select(
            'block_mistralagent_convs',
            "userid {$usersql} AND courseid = :courseid",
            $params,
            '',
            'id'
        );

        foreach ($conversations as $conv) {
            $DB->delete_records('block_mistralagent_msgs', ['conversationid' => $conv->id]);
        }

        $DB->delete_records_select(
            'block_mistralagent_convs',
            "userid {$usersql} AND courseid = :courseid",
            $params
        );

        $DB->delete_records_select(
            'block_mistralagent_quotas',
            "userid {$usersql} AND courseid = :courseid",
            $params
        );
    }
}
