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

namespace block_mistralagent\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use block_mistralagent\manager;
use block_mistralagent\mistral_client;
use block_mistralagent\resource_manager;

/**
 * Send message external function — v2 (blockinstanceid-based).
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_message extends external_api {
    /**
     * Define the parameters for the execute method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'blockinstanceid' => new external_value(PARAM_INT, 'Block instance ID'),
            'courseid'        => new external_value(PARAM_INT, 'Course ID'),
            'message'         => new external_value(PARAM_RAW, 'User message'),
            'conversationid'  => new external_value(PARAM_INT, 'Conversation ID', VALUE_DEFAULT, 0),
            'filecontent'     => new external_value(PARAM_RAW, 'Attached file content (plain text)', VALUE_DEFAULT, ''),
            'filename'        => new external_value(PARAM_RAW, 'Attached file name', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send a chat message and return the assistant response.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param string $message
     * @param int $conversationid
     * @param string $filecontent
     * @param string $filename
     * @return array
     */
    public static function execute(
        int $blockinstanceid,
        int $courseid,
        string $message,
        int $conversationid = 0,
        string $filecontent = '',
        string $filename = ''
    ): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'blockinstanceid' => $blockinstanceid,
            'courseid'        => $courseid,
            'message'         => $message,
            'conversationid'  => $conversationid,
            'filecontent'     => $filecontent,
            'filename'        => $filename,
        ]);

        $blockinstanceid = $params['blockinstanceid'];
        $courseid        = $params['courseid'];
        $message         = trim($params['message']);
        $conversationid  = $params['conversationid'];
        $filecontent     = $params['filecontent'];
        $filename        = $params['filename'];

        $limits = self::resolve_limits($blockinstanceid, $DB);

        /*
         * Les images (sentinel __IMAGE_BASE64__) ne sont pas du texte extrait : leur taille en base64 ne doit
         * should not be counted against the text limit.
         */
        $isimage = str_starts_with($filecontent, '__IMAGE_BASE64__');

        if (!$isimage && strlen($filecontent) > $limits['filecontent_chars']) {
            $maxkb = round($limits['filecontent_chars'] / 1000);
            return self::error_response(
                get_string('err_file_too_large', 'block_mistralagent', $maxkb),
                $conversationid
            );
        }

        if (empty($message) && empty($filecontent)) {
            return self::error_response(get_string('err_msg_required', 'block_mistralagent'), $conversationid);
        }

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/mistralagent:use', $context);

        $quota = manager::check_quota($USER->id, $blockinstanceid, $courseid);
        if (!$quota['allowed']) {
            return self::error_response(
                get_string('quotaexceeded', 'block_mistralagent'),
                $conversationid,
                $quota
            );
        }

        /*
         * Resolve the effective Mistral agent and API key for this instance. If the teacher has configured
         * their own key, it takes priority over the admin key.
         */
        $mistralagentid = manager::get_instance_mistral_agent_id($blockinstanceid);
        if (empty($mistralagentid)) {
            return self::error_response(
                get_string('noagentconfigured', 'block_mistralagent'),
                $conversationid,
                $quota
            );
        }

        $effectiveapikey = manager::get_instance_apikey($blockinstanceid);

        // Conserver $agent pour l'historique local (agentid en DB).
        $agentid = manager::get_instance_agent($blockinstanceid);
        $agent   = $agentid ? manager::get_agent($agentid) : null;
        // For instances with a personal key, agentid may be 0 — create a minimal stub object.
        if (!$agent) {
            $agent          = new \stdClass();
            $agent->id      = 0;
            $agent->agent_id = $mistralagentid;
            $agent->name    = 'Custom Agent';
        }

        // Resolve or create conversation scoped to this block instance.
        if ($conversationid > 0) {
            $conversation = $DB->get_record('block_mistralagent_convs', [
                'id'              => $conversationid,
                'userid'          => $USER->id,
                'blockinstanceid' => $blockinstanceid,
            ]);
            if (!$conversation) {
                $conversation = manager::get_or_create_conversation(
                    $USER->id,
                    $blockinstanceid,
                    $courseid,
                    $agentid
                );
            }
        } else {
            $conversation = manager::get_or_create_conversation(
                $USER->id,
                $blockinstanceid,
                $courseid,
                $agentid
            );
        }

        $savedmessage = $message;
        if (!empty($filename)) {
            $savedmessage = "[Fichier joint: {$filename}]\n\n" . $message;
        }

        $fullmessage = self::build_full_message($message, $filecontent, $filename);

        try {
            $relevantchunks = resource_manager::search_relevant_chunks(
                $blockinstanceid,
                $message ?: $filename,
                $limits['rag_chunks']
            );
            if (!empty($relevantchunks)) {
                $ragcontext  = resource_manager::build_context($relevantchunks);
                $sep = get_string('rag_question_separator', 'block_mistralagent');
                $fullmessage = $ragcontext . "\n\n--- " . $sep . " ---\n\n" . $fullmessage;
            }
        } catch (\Exception $e) {
            debugging('RAG search failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        try {
            $client = new mistral_client($mistralagentid, $effectiveapikey);

            // Detect whether filecontent is a base64-encoded image.
            $imagebase64 = '';
            if (str_starts_with($filecontent, '__IMAGE_BASE64__')) {
                $imagebase64 = 'data:' . substr($filecontent, strlen('__IMAGE_BASE64__'));
            }

            if (!empty($conversation->mistral_conversation_id)) {
                $result = $client->continue_conversation(
                    $conversation->mistral_conversation_id,
                    $fullmessage,
                    $imagebase64
                );
            } else {
                $result = $client->start_conversation($fullmessage, $imagebase64);
                $DB->set_field(
                    'block_mistralagent_convs',
                    'mistral_conversation_id',
                    $result['mistral_conversation_id'],
                    ['id' => $conversation->id]
                );
            }

            $response = $result['content'];
            $images   = $result['images'] ?? [];

            if (empty($response) && empty($images)) {
                return self::error_response(
                    get_string('err_api_empty_response', 'block_mistralagent'),
                    (int)$conversation->id,
                    $quota
                );
            }

            try {
                manager::add_message($conversation->id, 'user', $savedmessage);
                manager::add_message($conversation->id, 'assistant', $response);
            } catch (\Exception $e) {
                debugging('MistralAgent: Failed to save messages: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            manager::increment_quota($USER->id, $blockinstanceid);
            $quota = manager::check_quota($USER->id, $blockinstanceid, $courseid);

            return [
                'success'        => true,
                'error'          => '',
                'response'       => $response,
                'images'         => $images,
                'conversationid' => (int)$conversation->id,
                'quota'          => $quota,
            ];
        } catch (\Exception $e) {
            debugging('MistralAgent Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::error_response(
                self::friendly_api_error($e->getMessage()),
                (int)$conversation->id,
                $quota
            );
        }
    }

    /**
     * Define the return structure for the execute method.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'        => new external_value(PARAM_BOOL, 'Success status'),
            'error'          => new external_value(PARAM_TEXT, 'Error message if any'),
            'response'       => new external_value(PARAM_RAW, 'Assistant response (Markdown)'),
            'images'         => new \core_external\external_multiple_structure(
                new external_value(PARAM_RAW, 'Image URL or base64 data-URI'),
                'Generated images',
                VALUE_DEFAULT,
                []
            ),
            'conversationid' => new external_value(PARAM_INT, 'Conversation ID'),
            'quota'          => new external_single_structure([
                'allowed'   => new external_value(PARAM_BOOL, 'Whether user can send more messages'),
                'used'      => new external_value(PARAM_INT, 'Messages used'),
                'limit'     => new external_value(PARAM_INT, 'Message limit', VALUE_OPTIONAL),
                'remaining' => new external_value(PARAM_INT, 'Messages remaining', VALUE_OPTIONAL),
                'unlimited' => new external_value(PARAM_BOOL, 'Whether quota is unlimited', VALUE_DEFAULT, false),
            ]),
        ]);
    }

    // Private helpers.

    /**
     * Resolve the preset limits for a block instance.
     *
     * @param int $blockinstanceid
     * @param \moodle_database $DB
     * @return array
     */
    private static function resolve_limits(int $blockinstanceid, \moodle_database $DB): array {
        $instance = $DB->get_record('block_instances', ['id' => $blockinstanceid]);
        $instanceconfig = null;
        if ($instance && !empty($instance->configdata)) {
            $instanceconfig = unserialize(base64_decode($instance->configdata));
        }
        return \block_mistralagent\preset_manager::resolve_preset($instanceconfig ?: null);
    }

    /**
     * Build the full message text including attached file content.
     *
     * @param string $message
     * @param string $filecontent
     * @param string $filename
     * @return string
     */
    private static function build_full_message(string $message, string $filecontent, string $filename): string {
        if (empty($filecontent)) {
            return $message;
        }
        // Images : le texte commence par le sentinel, on ne les injecte pas comme texte.
        if (str_starts_with($filecontent, '__IMAGE_BASE64__')) {
            return !empty($message)
                ? $message
                : get_string('file_context_image_analyse', 'block_mistralagent');
        }
        $full  = get_string('file_context_intro', 'block_mistralagent', $filename) . "\n\n";
        $full .= get_string('file_context_start', 'block_mistralagent') . "\n";
        $full .= $filecontent;
        $full .= "\n" . get_string('file_context_end', 'block_mistralagent') . "\n\n";
        $full .= !empty($message)
            ? get_string('file_context_question', 'block_mistralagent', $message)
            : get_string('file_context_analyse', 'block_mistralagent');
        return $full;
    }

    /**
     * Build a standard error response array.
     *
     * @param string $errormsg
     * @param int $conversationid
     * @param array $quota
     * @return array
     */
    private static function error_response(string $errormsg, int $conversationid = 0, array $quota = []): array {
        if (empty($quota)) {
            $quota = ['allowed' => false, 'used' => 0, 'unlimited' => false];
        }
        return [
            'success'        => false,
            'error'          => $errormsg,
            'response'       => '',
            'conversationid' => $conversationid,
            'quota'          => $quota,
        ];
    }

    /**
     * Convert a raw API error into a user-friendly message.
     *
     * @param string $raw
     * @return string
     */
    private static function friendly_api_error(string $raw): string {
        if (stripos($raw, 'cURL') !== false) {
            return get_string('err_api_curl', 'block_mistralagent') . ' Detail: ' . $raw;
        }
        if (stripos($raw, 'timeout') !== false) {
            return get_string('err_api_timeout', 'block_mistralagent');
        }
        return $raw;
    }
}
