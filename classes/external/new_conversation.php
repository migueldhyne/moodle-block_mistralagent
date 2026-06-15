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
 * External web service to start a new conversation.
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
use core_external\external_value;
use block_mistralagent\manager;


/**
 * Web service: start a new conversation for the current user.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class new_conversation extends external_api {
    /**
     * Define the parameters for the execute method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'blockinstanceid' => new external_value(PARAM_INT, 'Block instance ID'),
            'courseid'        => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Create a new conversation and return its ID.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @return array
     */
    public static function execute(int $blockinstanceid, int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'blockinstanceid' => $blockinstanceid,
            'courseid'        => $courseid,
        ]);

        $blockinstanceid = $params['blockinstanceid'];
        $courseid        = $params['courseid'];

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/mistralagent:use', $context);

        /*
         * Personal teacher agents do not have a row in block_mistralagent_agents, so their local
         * agentid is 0. The effective Mistral agent ID is the reliable configuration check.
         */
        $mistralagentid = manager::get_instance_mistral_agent_id($blockinstanceid);
        if (empty($mistralagentid)) {
            return ['success' => false, 'conversationid' => 0,
                    'error'   => get_string('noagentconfigured', 'block_mistralagent')];
        }

        $agentid = manager::get_instance_agent($blockinstanceid) ?: 0;
        $conversation = manager::create_conversation($USER->id, $blockinstanceid, $courseid, $agentid);

        return ['success' => true, 'conversationid' => (int)$conversation->id, 'error' => ''];
    }

    /**
     * Define the return structure for the execute method.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'        => new external_value(PARAM_BOOL, 'Success status'),
            'conversationid' => new external_value(PARAM_INT,  'New conversation ID'),
            'error'          => new external_value(PARAM_TEXT, 'Error message if any'),
        ]);
    }
}
