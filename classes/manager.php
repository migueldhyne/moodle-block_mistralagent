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

namespace block_mistralagent;

/**
 * Manager class for Mistral Agent block.
 *
 * v2 — All data isolation is done on blockinstanceid.
 * courseid is kept as a secondary field for reporting only.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * Get the agent ID configured for a block instance.
     *
     * @param int $blockinstanceid
     * @return int|null
     */
    public static function get_instance_agent(int $blockinstanceid): ?int {
        global $DB;
        $record = $DB->get_record('block_mistralagent_course', ['blockinstanceid' => $blockinstanceid]);
        return $record ? (int)$record->agentid : null;
    }

    /**
     * Backward-compat alias used by pages that only know the courseid.
     * Returns the agent of the FIRST block instance found in that course.
     * Prefer get_instance_agent() wherever blockinstanceid is available.
     *
     * @param int $courseid
     * @return int|null
     */
    public static function get_course_agent(int $courseid): ?int {
        global $DB;
        $record = $DB->get_record('block_mistralagent_course', ['courseid' => $courseid]);
        return $record ? (int)$record->agentid : null;
    }

    /**
     * Set the agent for a block instance.
     *
     * @param int $blockinstanceid
     * @param int $courseid         Stored for reporting; not used as isolation key.
     * @param int $agentid
     * @return bool
     */
    public static function set_instance_agent(int $blockinstanceid, int $courseid, int $agentid): bool {
        global $DB, $USER;

        $now      = time();
        $existing = $DB->get_record('block_mistralagent_course', ['blockinstanceid' => $blockinstanceid]);

        if ($existing) {
            $existing->agentid      = $agentid;
            $existing->timemodified = $now;
            return $DB->update_record('block_mistralagent_course', $existing);
        }

        $record                  = new \stdClass();
        $record->blockinstanceid = $blockinstanceid;
        $record->courseid        = $courseid;
        $record->agentid         = $agentid;
        $record->timecreated     = $now;
        $record->timemodified    = $now;
        $record->createdby       = $USER->id;
        return (bool)$DB->insert_record('block_mistralagent_course', $record);
    }

    // Per-user personal API key.

    /**
     * Get the saved personal API key for the current user.
     * Returns empty string if none saved.
     *
     * @param int $userid
     * @return string  Decrypted API key, or '' if none.
     */
    public static function get_user_apikey(int $userid): string {
        global $DB;
        $record = $DB->get_record('block_mistralagent_user_keys', ['userid' => $userid]);
        if (!$record || empty($record->apikey)) {
            return '';
        }
        return self::decrypt_apikey((string)$record->apikey);
    }

    /**
     * Save (or update) a personal API key for a user.
     *
     * @param int    $userid
     * @param string $apikey  Plain-text API key.
     * @return bool
     */
    public static function save_user_apikey(int $userid, string $apikey): bool {
        global $DB;
        $encrypted = self::encrypt_apikey($apikey);
        $now       = time();
        $existing  = $DB->get_record('block_mistralagent_user_keys', ['userid' => $userid]);
        if ($existing) {
            $existing->apikey       = $encrypted;
            $existing->timemodified = $now;
            return $DB->update_record('block_mistralagent_user_keys', $existing);
        }
        $record               = new \stdClass();
        $record->userid       = $userid;
        $record->apikey       = $encrypted;
        $record->timecreated  = $now;
        $record->timemodified = $now;
        return (bool)$DB->insert_record('block_mistralagent_user_keys', $record);
    }

    /**
     * Delete the personal API key for a user.
     *
     * @param int $userid
     * @return bool
     */
    public static function delete_user_apikey(int $userid): bool {
        global $DB;
        return $DB->delete_records('block_mistralagent_user_keys', ['userid' => $userid]);
    }

    /**
     * Check if a user has a saved personal API key.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_user_apikey(int $userid): bool {
        global $DB;
        return $DB->record_exists('block_mistralagent_user_keys', ['userid' => $userid]);
    }

    // Per-instance API key.

    /**
     * Get the effective API key for a block instance.
     * Returns the teacher's personal key if configured, otherwise the global admin key.
     *
     * @param int $blockinstanceid
     * @return string
     */
    public static function get_instance_apikey(int $blockinstanceid): string {
        global $DB;
        $record = $DB->get_record('block_mistralagent_course', ['blockinstanceid' => $blockinstanceid]);
        if ($record && !empty($record->use_custom_key) && !empty($record->custom_apikey)) {
            return self::decrypt_apikey((string)$record->custom_apikey);
        }
        return (string)(get_config('block_mistralagent', 'apikey') ?: '');
    }

    /**
     * Get the effective agent Mistral ID for a block instance.
     * Returns the teacher's custom agent ID if configured, otherwise looks up the admin agent.
     *
     * @param int $blockinstanceid
     * @return string|null  Mistral agent ID (ag:…) or null if not configured.
     */
    public static function get_instance_mistral_agent_id(int $blockinstanceid): ?string {
        global $DB;
        $record = $DB->get_record('block_mistralagent_course', ['blockinstanceid' => $blockinstanceid]);
        if (!$record) {
            return null;
        }
        if (!empty($record->use_custom_key) && !empty($record->custom_agent_id)) {
            return $record->custom_agent_id;
        }
        // Admin agent — look up in block_mistralagent_agents.
        if (!empty($record->agentid)) {
            $agent = $DB->get_record('block_mistralagent_agents', ['id' => $record->agentid]);
            return $agent ? $agent->agent_id : null;
        }
        return null;
    }

    /**
     * Save a teacher's personal API key and agent for a block instance.
     *
     * @param int    $blockinstanceid
     * @param int    $courseid
     * @param string $apikey          Personal Mistral API key.
     * @param string $agentid        Personal Mistral agent ID (ag:…).
     * @param string $agentname      Display name of the agent.
     * @param string $agentdesc       Optional description of the agent.
     * @return bool
     */
    public static function set_instance_custom(int $blockinstanceid, int $courseid,
                                               string $apikey, string $agentid,
                                               string $agentname, string $agentdesc = ''): bool {
        global $DB, $USER;
        $now      = time();
        $existing = $DB->get_record('block_mistralagent_course', ['blockinstanceid' => $blockinstanceid]);

        $encrypted = self::encrypt_apikey($apikey);

        if ($existing) {
            $existing->use_custom_key    = 1;
            $existing->custom_apikey     = $encrypted;
            $existing->custom_agent_id   = $agentid;
            $existing->custom_agent_name = $agentname;
            $existing->custom_agent_desc = $agentdesc;
            $existing->timemodified      = $now;
            return $DB->update_record('block_mistralagent_course', $existing);
        }

        $record                    = new \stdClass();
        $record->blockinstanceid   = $blockinstanceid;
        $record->courseid          = $courseid;
        $record->agentid           = 0;
        $record->use_custom_key    = 1;
        $record->custom_apikey     = $encrypted;
        $record->custom_agent_id   = $agentid;
        $record->custom_agent_name = $agentname;
        $record->custom_agent_desc = $agentdesc;
        $record->timecreated       = $now;
        $record->timemodified      = $now;
        $record->createdby         = $USER->id;
        return (bool)$DB->insert_record('block_mistralagent_course', $record);
    }

    /**
     * Switch a block instance back to using the admin key.
     *
     * @param int $blockinstanceid
     * @return bool
     */
    public static function set_instance_use_admin_key(int $blockinstanceid): bool {
        global $DB;
        $record = $DB->get_record('block_mistralagent_course', ['blockinstanceid' => $blockinstanceid]);
        if ($record) {
            $record->use_custom_key = 0;
            $record->timemodified   = time();
            return $DB->update_record('block_mistralagent_course', $record);
        }
        return false;
    }

    /**
     * Simple reversible encryption for storing API keys in the DB.
     * Uses Moodle's built-in RC4 via the site secret.
     *
     * @param string $key
     * @return string
     */
    private static function encrypt_apikey(string $key): string {
        if (empty($key)) {
            return '';
        }
        $secret = get_site_identifier();
        return base64_encode(self::rc4crypt($key, $secret));
    }

    /**
     * Decrypt a stored API key.
     *
     * @param string $encrypted
     * @return string
     */
    private static function decrypt_apikey(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }
        $secret = get_site_identifier();
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            return $encrypted; // Already plain (legacy).
        }
        return self::rc4crypt($decoded, $secret);
    }

    /**
     * RC4 symmetric encryption/decryption helper.
     *
     * @param string $data
     * @param string $key
     * @return string
     */
    private static function rc4crypt(string $data, string $key): string {
        $s = range(0, 255);
        $j = 0;
        $keylen = strlen($key);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keylen])) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = $j = 0;
        $out = '';
        for ($k = 0; $k < strlen($data); $k++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) % 256]);
        }
        return $out;
    }

    // Agents.

    /**
     * Return the configured admin agents.
     *
     * @param bool $enabledonly
     * @return array
     */
    public static function get_agents(bool $enabledonly = true): array {
        global $DB;
        $params = $enabledonly ? ['enabled' => 1] : [];
        return $DB->get_records('block_mistralagent_agents', $params, 'name ASC');
    }

    /**
     * Return a single agent by its ID.
     *
     * @param int $agentid
     * @return \stdClass
     */
    public static function get_agent(int $agentid): ?\stdClass {
        global $DB;
        return $DB->get_record('block_mistralagent_agents', ['id' => $agentid]) ?: null;
    }

    /**
     * Create a new admin agent.
     *
     * @param string $name
     * @param string $mistralagentid
     * @param string $description
     * @return int
     */
    public static function create_agent(string $name, string $mistralagentid, string $description = ''): int {
        global $DB, $USER;
        $now            = time();
        $record         = new \stdClass();
        $record->name   = $name;
        $record->agent_id    = $mistralagentid;
        $record->description = $description;
        $record->enabled     = 1;
        $record->timecreated  = $now;
        $record->timemodified = $now;
        $record->createdby    = $USER->id;
        return $DB->insert_record('block_mistralagent_agents', $record);
    }

    /**
     * Update an existing admin agent.
     *
     * @param int $id
     * @param string $name
     * @param string $mistralagentid
     * @param string $description
     * @param bool $enabled
     * @return bool
     */
    public static function update_agent(int $id, string $name, string $mistralagentid,
                                        string $description, bool $enabled): bool {
        global $DB;
        $record              = new \stdClass();
        $record->id          = $id;
        $record->name        = $name;
        $record->agent_id    = $mistralagentid;
        $record->description = $description;
        $record->enabled     = $enabled ? 1 : 0;
        $record->timemodified = time();
        return $DB->update_record('block_mistralagent_agents', $record);
    }

    /**
     * Delete an admin agent.
     *
     * @param int $id
     * @return bool
     */
    public static function delete_agent(int $id): bool {
        global $DB;
        if ($DB->record_exists('block_mistralagent_course', ['agentid' => $id])) {
            return false;
        }
        if ($DB->record_exists('block_mistralagent_convs', ['agentid' => $id])) {
            return false;
        }
        return $DB->delete_records('block_mistralagent_agents', ['id' => $id]);
    }

    // Conversations.

    /**
     * Get or create the most recent conversation for a user in a block instance.
     *
     * @param int $userid
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $agentid
     * @return \stdClass
     */
    public static function get_or_create_conversation(int $userid, int $blockinstanceid,
                                                       int $courseid, int $agentid): \stdClass {
        global $DB;

        $conversation = $DB->get_record_sql(
            "SELECT * FROM {block_mistralagent_convs}
             WHERE userid = ? AND blockinstanceid = ? AND agentid = ?
             ORDER BY timemodified DESC
             LIMIT 1",
            [$userid, $blockinstanceid, $agentid]
        );

        return $conversation ?: self::create_conversation($userid, $blockinstanceid, $courseid, $agentid);
    }

    /**
     * Create a new conversation.
     *
     * @param int    $userid
     * @param int    $blockinstanceid
     * @param int    $courseid
     * @param int    $agentid
     * @param string $title
     * @return \stdClass
     */
    public static function create_conversation(int $userid, int $blockinstanceid, int $courseid,
                                               int $agentid, string $title = ''): \stdClass {
        global $DB;

        $now                     = time();
        $record                  = new \stdClass();
        $record->userid          = $userid;
        $record->blockinstanceid = $blockinstanceid;
        $record->courseid        = $courseid;
        $record->agentid         = $agentid;
        $record->title           = $title;
        $record->timecreated     = $now;
        $record->timemodified    = $now;

        $record->id = $DB->insert_record('block_mistralagent_convs', $record);
        return $record;
    }

    /**
     * Store a message in a conversation.
     *
     * @param int $conversationid
     * @param string $role
     * @param string $content
     * @return int
     */
    public static function add_message(int $conversationid, string $role, string $content): int {
        global $DB;
        $now                    = time();
        $record                 = new \stdClass();
        $record->conversationid = $conversationid;
        $record->role           = $role;
        $record->content        = $content;
        $record->timecreated    = $now;
        $DB->set_field('block_mistralagent_convs', 'timemodified', $now, ['id' => $conversationid]);
        return $DB->insert_record('block_mistralagent_msgs', $record);
    }

    /**
     * Return the messages of a conversation.
     *
     * @param int $conversationid
     * @return array
     */
    public static function get_messages(int $conversationid): array {
        global $DB;
        return $DB->get_records('block_mistralagent_msgs', ['conversationid' => $conversationid], 'timecreated ASC');
    }

    /**
     * Get conversations for a block instance (teacher view).
     *
     * @param int      $blockinstanceid
     * @param int|null $userid
     * @return array
     */
    public static function get_instance_conversations(int $blockinstanceid, ?int $userid = null): array {
        global $DB;

        $params = ['blockinstanceid' => $blockinstanceid];
        $sql    = "SELECT c.*, u.firstname, u.lastname, u.email,
                          (SELECT COUNT(*) FROM {block_mistralagent_msgs} m WHERE m.conversationid = c.id) AS messagecount
                   FROM {block_mistralagent_convs} c
                   JOIN {user} u ON u.id = c.userid
                   WHERE c.blockinstanceid = :blockinstanceid";

        if ($userid !== null) {
            $params['userid'] = $userid;
            $sql .= " AND c.userid = :userid";
        }

        $sql .= " ORDER BY c.timemodified DESC";
        return $DB->get_records_sql($sql, $params);
    }

    // Quotas.

    /**
     * Check quota status for a user in a block instance.
     *
     * @param int $userid
     * @param int $blockinstanceid
     * @param int $courseid         Used only when creating the first quota record.
     * @return array
     */
    public static function check_quota(int $userid, int $blockinstanceid, int $courseid = 0): array {
        global $DB;

        $quota = $DB->get_record('block_mistralagent_quotas',
            ['userid' => $userid, 'blockinstanceid' => $blockinstanceid]);

        $defaultlimit = get_config('block_mistralagent', 'defaultquota');
        $period       = get_config('block_mistralagent', 'quotaperiod');
        $periodstart  = self::get_period_start($period);

        if (!$quota) {
            $quota                  = new \stdClass();
            $quota->userid          = $userid;
            $quota->blockinstanceid = $blockinstanceid;
            $quota->courseid        = $courseid;
            $quota->messages_used   = 0;
            $quota->messages_limit  = $defaultlimit ?: null;
            $quota->period_start    = $periodstart;
            $quota->timemodified    = time();
            $quota->id              = $DB->insert_record('block_mistralagent_quotas', $quota);
        } else if ($quota->period_start < $periodstart) {
            $quota->messages_used  = 0;
            $quota->period_start   = $periodstart;
            $quota->timemodified   = time();
            $DB->update_record('block_mistralagent_quotas', $quota);
        }

        if ($quota->messages_limit === null) {
            return ['allowed' => true, 'used' => (int)$quota->messages_used,
                    'limit' => null, 'remaining' => null, 'unlimited' => true];
        }

        $remaining = max(0, $quota->messages_limit - $quota->messages_used);
        return ['allowed' => $remaining > 0, 'used' => (int)$quota->messages_used,
                'limit' => (int)$quota->messages_limit, 'remaining' => $remaining, 'unlimited' => false];
    }

    /**
     * Atomic quota increment for a block instance.
     *
     * @param int $userid
     * @param int $blockinstanceid
     * @return bool
     */
    public static function increment_quota(int $userid, int $blockinstanceid): bool {
        global $DB;

        if (!$DB->record_exists('block_mistralagent_quotas',
                ['userid' => $userid, 'blockinstanceid' => $blockinstanceid])) {
            return false;
        }

        $DB->execute(
            "UPDATE {block_mistralagent_quotas}
             SET messages_used = messages_used + 1, timemodified = ?
             WHERE userid = ? AND blockinstanceid = ?",
            [time(), $userid, $blockinstanceid]
        );
        return true;
    }

    /**
     * Set user quota for a block instance.
     *
     * @param int      $userid
     * @param int      $blockinstanceid
     * @param int      $courseid
     * @param int|null $limit
     * @return bool
     */
    public static function set_user_quota(int $userid, int $blockinstanceid, int $courseid, ?int $limit): bool {
        global $DB;

        $context = \context_course::instance($courseid);
        require_capability('block/mistralagent:managequotas', $context);

        $quota = $DB->get_record('block_mistralagent_quotas',
            ['userid' => $userid, 'blockinstanceid' => $blockinstanceid]);

        if ($quota) {
            $quota->messages_limit = $limit;
            $quota->timemodified   = time();
            return $DB->update_record('block_mistralagent_quotas', $quota);
        }

        $period         = get_config('block_mistralagent', 'quotaperiod');
        $quota          = new \stdClass();
        $quota->userid          = $userid;
        $quota->blockinstanceid = $blockinstanceid;
        $quota->courseid        = $courseid;
        $quota->messages_used   = 0;
        $quota->messages_limit  = $limit;
        $quota->period_start    = self::get_period_start($period);
        $quota->timemodified    = time();
        return (bool)$DB->insert_record('block_mistralagent_quotas', $quota);
    }

    /**
     * Return the start timestamp of the current quota period.
     *
     * @param string $period
     * @return int
     */
    private static function get_period_start(string $period): int {
        switch ($period) {
            case 'daily':
                return strtotime('today midnight');
            case 'weekly':
                return strtotime('monday this week midnight');
            case 'monthly':
                return strtotime('first day of this month midnight');
            default:
                return strtotime('first day of this month midnight');
        }
    }
}
