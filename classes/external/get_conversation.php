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

/**
 * External web service to load a conversation history.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mistralagent\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use block_mistralagent\manager;


/**
 * Web service: load the messages of a conversation.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_conversation extends external_api {
    /**
     * Define the parameters for the execute method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'blockinstanceid' => new external_value(PARAM_INT, 'Block instance ID'),
            'courseid'        => new external_value(PARAM_INT, 'Course ID'),
            'conversationid'  => new external_value(PARAM_INT, 'Conversation ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Load the conversation history for the current user.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $conversationid
     * @return array
     */
    public static function execute(int $blockinstanceid, int $courseid, int $conversationid = 0): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'blockinstanceid' => $blockinstanceid,
            'courseid'        => $courseid,
            'conversationid'  => $conversationid,
        ]);

        $blockinstanceid = $params['blockinstanceid'];
        $courseid        = $params['courseid'];
        $conversationid  = $params['conversationid'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/mistralagent:use', $context);

        /*
         * Personal teacher agents have no local agent record. Use the effective Mistral agent ID
         * to decide whether the block is configured, and keep local agentid only for filtering.
         */
        $mistralagentid = manager::get_instance_mistral_agent_id($blockinstanceid);
        if (empty($mistralagentid)) {
            return ['success' => false, 'conversationid' => 0, 'messages' => []];
        }
        $agentid = manager::get_instance_agent($blockinstanceid) ?: 0;

        if ($conversationid > 0) {
            $conversation = $DB->get_record('block_mistralagent_convs', [
                'id'              => $conversationid,
                'userid'          => $USER->id,
                'blockinstanceid' => $blockinstanceid,
            ]);
        } else {
            $conversation = $DB->get_record_sql(
                "SELECT * FROM {block_mistralagent_convs}
                 WHERE userid = ? AND blockinstanceid = ? AND agentid = ?
                 ORDER BY timemodified DESC LIMIT 1",
                [$USER->id, $blockinstanceid, $agentid]
            );
        }

        if (!$conversation) {
            return ['success' => true, 'conversationid' => 0, 'messages' => []];
        }

        $messages = manager::get_messages($conversation->id);
        $result   = [];
        foreach ($messages as $msg) {
            $result[] = [
                'id'          => (int)$msg->id,
                'role'        => $msg->role,
                'content'     => $msg->content,
                'timecreated' => (int)$msg->timecreated,
            ];
        }

        return ['success' => true, 'conversationid' => (int)$conversation->id, 'messages' => $result];
    }

    /**
     * Define the return structure for the execute method.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'        => new external_value(PARAM_BOOL, 'Success status'),
            'conversationid' => new external_value(PARAM_INT,  'Conversation ID'),
            'messages'       => new external_multiple_structure(
                new external_single_structure([
                    'id'          => new external_value(PARAM_INT,   'Message ID'),
                    'role'        => new external_value(PARAM_ALPHA,  'Role: user or assistant'),
                    'content'     => new external_value(PARAM_RAW,   'Message content'),
                    'timecreated' => new external_value(PARAM_INT,   'Timestamp'),
                ])
            ),
        ]);
    }
}
