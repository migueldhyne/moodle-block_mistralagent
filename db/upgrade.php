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
 * Database upgrade steps for block_mistralagent.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run the database upgrade steps.
 *
 * @param mixed $oldversion
 */
function xmldb_block_mistralagent_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024120601) {
        $table = new xmldb_table('block_mistralagent_resources');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $table->add_index('courseid_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('block_mistralagent_chunks');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('resourceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('chunk_index', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('chunk_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedding', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('token_count', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('resourceid', XMLDB_KEY_FOREIGN, ['resourceid'], 'block_mistralagent_resources', ['id']);
        $table->add_index('resourceid_index', XMLDB_INDEX_NOTUNIQUE, ['resourceid', 'chunk_index']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2024120601, 'mistralagent');
    }

    if ($oldversion < 2024120621) {
        if ($DB->get_dbfamily() === 'mysql') {
            try {
                $DB->execute("ALTER TABLE {block_mistralagent_resources} MODIFY content LONGTEXT");
                $DB->execute("ALTER TABLE {block_mistralagent_chunks} MODIFY chunk_text LONGTEXT");
                $DB->execute("ALTER TABLE {block_mistralagent_chunks} MODIFY embedding LONGTEXT");
            } catch (\Exception $e) {
                debugging('MistralAgent: LONGTEXT migration: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        upgrade_block_savepoint(true, 2024120621, 'mistralagent');
    }

    if ($oldversion < 2024120623) {
        $table = new xmldb_table('block_mistralagent_convs');
        $field = new xmldb_field('mistral_conversation_id', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'title');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_block_savepoint(true, 2024120623, 'mistralagent');
    }

    // V2 : Multi-bloc — remplacement de courseid par blockinstanceid.
    if ($oldversion < 2026042705) {
        /*
         * 1. block_mistralagent_course: add blockinstanceid, drop the unique index on courseid, create
         * l'index unique sur blockinstanceid.
         */
        $table = new xmldb_table('block_mistralagent_course');

        $field = new xmldb_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        /*
         * Populate blockinstanceid from block_instances for existing rows. We take the
         * first mistralagent instance found in the course.
         */
        $records = $DB->get_records('block_mistralagent_course');
        foreach ($records as $rec) {
            if ($rec->blockinstanceid == 0) {
                $bi = $DB->get_record_sql(
                    "SELECT bi.id FROM {block_instances} bi
                      JOIN {context} ctx ON ctx.id = bi.parentcontextid
                     WHERE bi.blockname = 'mistralagent'
                       AND ctx.contextlevel = :ctxlevel
                       AND ctx.instanceid   = :courseid
                     LIMIT 1",
                    ['ctxlevel' => CONTEXT_COURSE, 'courseid' => $rec->courseid]
                );
                if ($bi) {
                    $DB->set_field('block_mistralagent_course', 'blockinstanceid', $bi->id, ['id' => $rec->id]);
                }
            }
        }

        // Supprimer l'ancien index unique sur courseid.
        $index = new xmldb_index('courseid_unique', XMLDB_INDEX_UNIQUE, ['courseid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Create the new unique index on blockinstanceid.
        $index = new xmldb_index('blockinstanceid_unique', XMLDB_INDEX_UNIQUE, ['blockinstanceid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 2. block_mistralagent_convs : ajouter blockinstanceid.
        $table = new xmldb_table('block_mistralagent_convs');

        $field = new xmldb_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Populate from courseid using the same logic.
        $DB->execute(
            "UPDATE {block_mistralagent_convs} c
                JOIN {block_mistralagent_course} bc ON bc.courseid = c.courseid
             SET c.blockinstanceid = bc.blockinstanceid
             WHERE c.blockinstanceid = 0"
        );

        // Remplacer l'index userid_courseid par userid_blockinstanceid.
        $index = new xmldb_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('userid_blockinstanceid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'blockinstanceid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 3. block_mistralagent_resources : ajouter blockinstanceid.
        $table = new xmldb_table('block_mistralagent_resources');

        $field = new xmldb_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $DB->execute(
            "UPDATE {block_mistralagent_resources} r
                JOIN {block_mistralagent_course} bc ON bc.courseid = r.courseid
             SET r.blockinstanceid = bc.blockinstanceid
             WHERE r.blockinstanceid = 0"
        );

        // Remplacer l'index courseid_status par blockinstanceid_status.
        $index = new xmldb_index('courseid_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('blockinstanceid_status', XMLDB_INDEX_NOTUNIQUE, ['blockinstanceid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 4. block_mistralagent_quotas : ajouter blockinstanceid.
        $table = new xmldb_table('block_mistralagent_quotas');

        $field = new xmldb_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $DB->execute(
            "UPDATE {block_mistralagent_quotas} q
                JOIN {block_mistralagent_course} bc ON bc.courseid = q.courseid
             SET q.blockinstanceid = bc.blockinstanceid
             WHERE q.blockinstanceid = 0"
        );

        // Remplacer l'index unique userid_courseid par userid_blockinstanceid.
        $index = new xmldb_index('userid_courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('userid_blockinstanceid', XMLDB_INDEX_UNIQUE, ['userid', 'blockinstanceid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_block_savepoint(true, 2026042705, 'mistralagent');
    }

    // V3: Teacher's personal API key.
    if ($oldversion < 2026042801) {
        $table = new xmldb_table('block_mistralagent_course');

        $field = new xmldb_field('custom_apikey', XMLDB_TYPE_TEXT, null, null, null, null, null, 'createdby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('custom_agent_id', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'custom_apikey');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('custom_agent_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'custom_agent_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('custom_agent_desc', XMLDB_TYPE_TEXT, null, null, null, null, null, 'custom_agent_name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('use_custom_key', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'custom_agent_desc');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026042801, 'mistralagent');
    }

    // V3.1: Per-user personal API key.
    if ($oldversion < 2026042819) {
        $table = new xmldb_table('block_mistralagent_user_keys');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('apikey', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_block_savepoint(true, 2026042819, 'mistralagent');
    }

    return true;
}
