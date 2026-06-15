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
 * Settings for block_mistralagent.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Register external admin page for agent management.
$ADMIN->add('blocksettings', new admin_externalpage(
    'block_mistralagent_agents',
    get_string('manageagents', 'block_mistralagent'),
    new moodle_url('/blocks/mistralagent/agents.php'),
    'block/mistralagent:manageagents'
));

if ($ADMIN->fulltree) {
    // Section separator.
    // API.
    // Section separator.

    $settings->add(new admin_setting_heading(
        'block_mistralagent/heading_api',
        get_string('settings_heading_api', 'block_mistralagent'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'block_mistralagent/apikey',
        get_string('apikey', 'block_mistralagent'),
        get_string('apikey_desc', 'block_mistralagent'),
        ''
    ));

    // Section separator.
    // Quotas.
    // Section separator.

    $settings->add(new admin_setting_heading(
        'block_mistralagent/heading_quota',
        get_string('settings_heading_quota', 'block_mistralagent'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_mistralagent/defaultquota',
        get_string('defaultquota', 'block_mistralagent'),
        get_string('defaultquota_desc', 'block_mistralagent'),
        '100',
        PARAM_INT
    ));

    $periods = [
        'daily'   => get_string('period_daily', 'block_mistralagent'),
        'weekly'  => get_string('period_weekly', 'block_mistralagent'),
        'monthly' => get_string('period_monthly', 'block_mistralagent'),
    ];
    $settings->add(new admin_setting_configselect(
        'block_mistralagent/quotaperiod',
        get_string('quotaperiod', 'block_mistralagent'),
        get_string('quotaperiod_desc', 'block_mistralagent'),
        'monthly',
        $periods
    ));

    // Section separator.
    // Models.
    // Section separator.
    /*
     * Each model can be changed here without touching any PHP class. Use the exact model ID from
     * https://docs.mistral.ai/getting-started/models/.
     */
    // Section separator.

    $settings->add(new admin_setting_heading(
        'block_mistralagent/heading_models',
        get_string('settings_heading_models', 'block_mistralagent'),
        get_string('settings_heading_models_desc', 'block_mistralagent')
    ));

    $settings->add(new admin_setting_configtext(
        'block_mistralagent/model_ocr',
        get_string('model_ocr', 'block_mistralagent'),
        get_string('model_ocr_desc', 'block_mistralagent'),
        'mistral-ocr-latest',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_mistralagent/model_embed',
        get_string('model_embed', 'block_mistralagent'),
        get_string('model_embed_desc', 'block_mistralagent'),
        'mistral-embed',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_mistralagent/model_image',
        get_string('model_image', 'block_mistralagent'),
        get_string('model_image_desc', 'block_mistralagent'),
        'pixtral-1-25-01',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_mistralagent/model_vision',
        get_string('model_vision', 'block_mistralagent'),
        get_string('model_vision_desc', 'block_mistralagent'),
        'pixtral-12b-2409',
        PARAM_TEXT
    ));

    // Section separator.
    // RAG — chunk embedding limit.
    // Section separator.

    $settings->add(new admin_setting_heading(
        'block_mistralagent/heading_rag',
        get_string('settings_heading_rag', 'block_mistralagent'),
        get_string('settings_heading_rag_desc', 'block_mistralagent')
    ));

    $settings->add(new admin_setting_configtext(
        'block_mistralagent/max_embedding_chunks',
        get_string('max_embedding_chunks', 'block_mistralagent'),
        get_string('max_embedding_chunks_desc', 'block_mistralagent'),
        '50',
        PARAM_INT
    ));

    // Section separator.
    /*
     * Context presets — admin sets the MAXIMUM preset teachers may select.
     * Preset values (also used in edit_form.php and send_message.php):
     *   light    : history_chars 10000, filecontent_chars  50000, rag_chunks 3, history_msgs 10.
     *   standard : history_chars 30000, filecontent_chars 200000, rag_chunks 5, history_msgs 20.
     *   full     : history_chars 60000, filecontent_chars 400000, rag_chunks 8, history_msgs 40.
     * Setting the max to "standard" means teachers can choose "light" or "standard" but NOT "full".
     */
    // Section separator.

    $settings->add(new admin_setting_heading(
        'block_mistralagent/heading_presets',
        get_string('settings_heading_presets', 'block_mistralagent'),
        get_string('settings_heading_presets_desc', 'block_mistralagent')
    ));

    $presetoptions = [
        'light'    => get_string('preset_light', 'block_mistralagent'),
        'standard' => get_string('preset_standard', 'block_mistralagent'),
        'full'     => get_string('preset_full', 'block_mistralagent'),
    ];

    $settings->add(new admin_setting_configselect(
        'block_mistralagent/max_preset',
        get_string('max_preset', 'block_mistralagent'),
        get_string('max_preset_desc', 'block_mistralagent'),
        'standard', // Default max: teachers can choose light or standard.
        $presetoptions
    ));

    $presets = \block_mistralagent\preset_manager::get_preset_definitions();
    $presetcolours = ['light' => '#d4edda', 'standard' => '#fff3cd', 'full' => '#f8d7da'];
    $presetheaders = [
        get_string('preset_col_history_chars', 'block_mistralagent'),
        get_string('preset_col_filecontent_chars', 'block_mistralagent'),
        get_string('preset_col_rag_chunks', 'block_mistralagent'),
        get_string('preset_col_history_messages', 'block_mistralagent'),
    ];

    $presethtml = '<div class="alert alert-info mt-2 mb-0">';
    $presethtml .= '<strong>' . get_string('config_summary_title', 'block_mistralagent') . '</strong>';
    $presethtml .= '<p class="mb-2">' . get_string('config_summary_intro', 'block_mistralagent') . '</p>';
    $presethtml .= '<table class="table table-bordered table-sm mb-0" style="font-size:.9rem">';
    $presethtml .= '<thead><tr>';
    $presethtml .= '<th>' . get_string('preset_col_name', 'block_mistralagent') . '</th>';
    foreach ($presetheaders as $presetheader) {
        $presethtml .= '<th>' . $presetheader . '</th>';
    }
    $presethtml .= '</tr></thead><tbody>';

    foreach ($presetoptions as $presetkey => $presetlabel) {
        $preset = $presets[$presetkey];
        $presetcolour = $presetcolours[$presetkey] ?? '#ffffff';
        $presethtml .= '<tr style="background:' . $presetcolour . '">';
        $presethtml .= '<td><strong>' . $presetlabel . '</strong></td>';
        $presethtml .= '<td>' . number_format($preset['history_chars'] / 1000, 0) . ' K cars</td>';
        $presethtml .= '<td>' . number_format($preset['filecontent_chars'] / 1000, 0) . ' K cars</td>';
        $presethtml .= '<td>' . $preset['rag_chunks'] . ' ' . get_string('chunks', 'block_mistralagent') . '</td>';
        $presethtml .= '<td>' . $preset['history_messages'] . ' ' . get_string('messages', 'block_mistralagent') . '</td>';
        $presethtml .= '</tr>';
    }

    $presethtml .= '</tbody></table>';
    $presethtml .= '<ul class="mt-2 mb-0" style="font-size:.85rem">';
    $presethtml .= '<li>' . get_string('config_consequence_history_chars', 'block_mistralagent') . '</li>';
    $presethtml .= '<li>' . get_string('config_consequence_filecontent_chars', 'block_mistralagent') . '</li>';
    $presethtml .= '<li>' . get_string('config_consequence_rag_chunks', 'block_mistralagent') . '</li>';
    $presethtml .= '<li>' . get_string('config_consequence_history_messages', 'block_mistralagent') . '</li>';
    $presethtml .= '</ul>';
    $presethtml .= '</div>';

    $settings->add(new admin_setting_description(
        'block_mistralagent/max_preset_explanation',
        '',
        $presethtml
    ));

    // Section separator.
    // Agent management.
    // Section separator.

    $settings->add(new admin_setting_heading(
        'block_mistralagent/heading_manageagents',
        get_string('settings_heading_manageagents', 'block_mistralagent'),
        get_string('settings_heading_manageagents_desc', 'block_mistralagent')
    ));

    $manageurl = new moodle_url('/blocks/mistralagent/agents.php');
    $managebutton = html_writer::div(
        html_writer::link($manageurl, get_string('manageagents_link', 'block_mistralagent'), [
            'class' => 'btn btn-secondary',
            'style' => 'margin-bottom: 1.5rem;',
        ]),
        'block-mistralagent-admin-actions mb-4 pb-2'
    );
    $settings->add(new admin_setting_description(
        'block_mistralagent/manageagents',
        '',
        $managebutton
    ));
}
