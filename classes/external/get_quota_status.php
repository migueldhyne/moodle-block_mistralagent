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
 * External web service to check the remaining message quota.
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
 * Web service: return the current user message quota status.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_quota_status extends external_api {
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
     * Return the remaining quota for the current user.
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

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/mistralagent:use', $context);

        return manager::check_quota($USER->id, $params['blockinstanceid'], $params['courseid']);
    }

    /**
     * Define the return structure for the execute method.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'allowed'   => new external_value(PARAM_BOOL, 'Whether user can send more messages'),
            'used'      => new external_value(PARAM_INT,  'Messages used'),
            'limit'     => new external_value(PARAM_INT,  'Message limit', VALUE_OPTIONAL),
            'remaining' => new external_value(PARAM_INT,  'Messages remaining', VALUE_OPTIONAL),
            'unlimited' => new external_value(PARAM_BOOL, 'Whether quota is unlimited', VALUE_DEFAULT, false),
        ]);
    }
}
