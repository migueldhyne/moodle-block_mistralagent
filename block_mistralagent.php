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
 * Block mistralagent main class — v2 (multi-instance).
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_mistralagent extends block_base {

    /**
     * Return the preset definitions (history, file, chunk and message limits).
     *
     * @return array
     */
    public static function get_preset_definitions(): array {
        return [
            'light'    => ['history_chars' => 10000, 'filecontent_chars' => 50000,  'rag_chunks' => 3, 'history_messages' => 10],
            'standard' => ['history_chars' => 30000, 'filecontent_chars' => 200000, 'rag_chunks' => 5, 'history_messages' => 20],
            'full'     => ['history_chars' => 60000, 'filecontent_chars' => 400000, 'rag_chunks' => 8, 'history_messages' => 40],
        ];
    }

    /**
     * Resolve the effective preset limits for a block instance.
     *
     * @param  stdClass|null $instanceconfig  Block instance configuration object, or null if unconfigured.
     * @return array                          Associative array of preset limit values.
     */
    public static function resolve_preset(?stdClass $instanceconfig): array {
        $presets   = self::get_preset_definitions();
        $order     = ['light', 'standard', 'full'];
        $maxpreset = get_config('block_mistralagent', 'max_preset') ?: 'standard';
        $maxindex  = array_search($maxpreset, $order);
        $chosen    = $instanceconfig->preset ?? 'standard';
        $chosenidx = array_search($chosen, $order);
        if ($chosenidx === false || $chosenidx > $maxindex) {
            $chosen = $maxpreset;
        }
        return $presets[$chosen];
    }

    /**
     * Initialise the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_mistralagent');
    }

    /**
     * Whether each block instance can be configured.
     *
     * @return bool
     */
    public function instance_allow_config(): bool {
        return true;
    }

    /**
     * Allow multiple instances per course — the key change of v2.
     */
    public function instance_allow_multiple(): bool {
        return true;
    }

    /**
     * Whether the block has global configuration.
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Build and return the block content.
     */
    public function get_content() {
        global $USER, $COURSE, $OUTPUT, $DB;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content       = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        $context = context_course::instance($COURSE->id);
        if (!has_capability('block/mistralagent:use', $context)) {
            return $this->content;
        }

        $blockinstanceid = (int)$this->instance->id;

        // Resolve the displayed agent — teacher key takes priority when configured.
        $instancerec     = $DB->get_record('block_mistralagent_course', ['blockinstanceid' => $blockinstanceid]);
        $mistralagentid = \block_mistralagent\manager::get_instance_mistral_agent_id($blockinstanceid);

        if (!$mistralagentid) {
            if (has_capability('block/mistralagent:configureagent', $context)) {
                $configurl = new moodle_url('/blocks/mistralagent/configure.php', [
                    'blockinstanceid' => $blockinstanceid,
                    'courseid'        => $COURSE->id,
                ]);
                $this->content->text = $OUTPUT->notification(
                    get_string('noagentconfigured', 'block_mistralagent') . ' ' .
                    html_writer::link($configurl, get_string('configureagent', 'block_mistralagent')),
                    'warning'
                );
            } else {
                $this->content->text = $OUTPUT->notification(
                    get_string('noagentavailable', 'block_mistralagent'), 'info'
                );
            }
            return $this->content;
        }

        // Construire l'objet $agent selon le mode.
        if (!empty($instancerec->use_custom_key)) {
            // Teacher mode — name and description come from Mistral (saved at configuration time).
            $agent              = new stdClass();
            $agent->name        = $instancerec->custom_agent_name ?: $instancerec->custom_agent_id;
            $agent->description = $instancerec->custom_agent_desc ?? '';
            $agent->agent_id    = $instancerec->custom_agent_id;
        } else {
            // Mode admin — chercher dans block_mistralagent_agents.
            $agentid = \block_mistralagent\manager::get_instance_agent($blockinstanceid);
            $agent   = $agentid ? \block_mistralagent\manager::get_agent($agentid) : null;
        }
        $presetkey   = $this->config->preset ?? 'standard';
        $presetlabel = get_string('preset_' . $presetkey, 'block_mistralagent');
        $canconfig   = has_capability('block/mistralagent:configureagent', $context);

        $params = ['blockinstanceid' => $blockinstanceid, 'courseid' => $COURSE->id];

        $templatecontext = [
            'blockinstanceid'   => $blockinstanceid,
            'courseid'          => $COURSE->id,
            'userid'            => $USER->id,
            'chatpageurl'       => (new moodle_url('/blocks/mistralagent/chat.php',        $params))->out(false),
            'agentname'         => $agent ? $agent->name : get_string('pluginname', 'block_mistralagent'),
            'agentdescription'  => $agent ? $agent->description : '',
            'myconversationsurl' => (new moodle_url('/blocks/mistralagent/myconversations.php', $params))->out(false),
            'canconfig'         => $canconfig,
            'configureurl'      => (new moodle_url('/blocks/mistralagent/configure.php',   $params))->out(false),
            'resourcesurl'      => (new moodle_url('/blocks/mistralagent/resources.php',   $params))->out(false),
            'historyurl'        => (new moodle_url('/blocks/mistralagent/history.php',     $params))->out(false),
            'presetlabel'       => $presetlabel,
        ];

        $this->content->text = $OUTPUT->render_from_template('block_mistralagent/block', $templatecontext);
        return $this->content;
    }

    /**
     * Define where this block can be added.
     */
    public function applicable_formats() {
        return ['course-view' => true, 'mod' => true];
    }
}
