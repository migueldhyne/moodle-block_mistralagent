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
 * Block instance configuration form.
 *
 * Lets teachers choose a context preset (Light / Standard / Full) within
 * the maximum level set by the administrator.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_mistralagent_edit_form extends block_edit_form {
    /**
     * Preset definitions: values applied for each preset level.
     * Must stay in sync with send_message.php::PRESETS.
     */
    private static function get_preset_definitions(): array {
        return [
            'light' => [
                'history_chars'    => 10000,
                'filecontent_chars' => 50000,
                'rag_chunks'       => 3,
                'history_messages' => 10,
            ],
            'standard' => [
                'history_chars'    => 30000,
                'filecontent_chars' => 200000,
                'rag_chunks'       => 5,
                'history_messages' => 20,
            ],
            'full' => [
                'history_chars'    => 60000,
                'filecontent_chars' => 400000,
                'rag_chunks'       => 8,
                'history_messages' => 40,
            ],
        ];
    }

    /**
     * Preset order used to enforce the admin ceiling.
     */
    private static function preset_order(): array {
        return ['light', 'standard', 'full'];
    }

    /**
     * Build the form.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform) {

        $presets    = self::get_preset_definitions();
        $order      = self::preset_order();
        $maxpreset  = get_config('block_mistralagent', 'max_preset') ?: 'standard';
        $maxindex   = array_search($maxpreset, $order);

        // Build the list of allowed preset options for this teacher.
        $allowedoptions = [];
        foreach ($order as $index => $key) {
            if ($index <= $maxindex) {
                $allowedoptions[$key] = get_string('preset_' . $key, 'block_mistralagent');
            }
        }

        /*
         * Global summary. Shown at the top of the form so teachers understand what each preset implies before
         * making a choice.
         */
        $summarytable = $this->build_summary_table($presets, $allowedoptions);
        $mform->addElement('html', $summarytable);

        // Preset selector.
        $mform->addElement('header', 'config_header',
            get_string('instanceconfig_header', 'block_mistralagent'));

        $mform->addElement('select', 'config_preset',
            get_string('config_preset', 'block_mistralagent'),
            $allowedoptions
        );
        $mform->setDefault('config_preset', 'standard');
        $mform->addHelpButton('config_preset', 'config_preset', 'block_mistralagent');

        /*
         * Per-field short help (shown below the select). Rendered as a small static HTML table so teachers can
         * see the exact values that will apply when they save their choice.
         */
        $mform->addElement('static', 'config_preset_detail', '',
            get_string('config_preset_detail_intro', 'block_mistralagent')
        );

        // RAG chunk override.
        $globalmax = (int)(get_config('block_mistralagent', 'max_embedding_chunks') ?: 50);

        $mform->addElement('header', 'config_rag_header',
            get_string('config_rag_header', 'block_mistralagent'));

        $mform->addElement('text', 'config_max_chunks',
            get_string('config_max_chunks', 'block_mistralagent'),
            ['size' => 5]
        );
        $mform->setType('config_max_chunks', PARAM_INT);
        $mform->setDefault('config_max_chunks', 0);
        $mform->addHelpButton('config_max_chunks', 'config_max_chunks', 'block_mistralagent');

        $mform->addElement('static', 'config_max_chunks_hint', '',
            get_string('config_max_chunks_hint', 'block_mistralagent', $globalmax)
        );

        $mform->addRule('config_max_chunks',
            get_string('config_max_chunks_invalid', 'block_mistralagent'),
            'regex', '/^[0-9]*$/', 'client'
        );
    }

    /**
     * Build the global impact summary shown at the top of the form.
     *
     * Four columns (one per parameter) x N rows (one per allowed preset).
     * Each cell is colour-coded green → amber → red to signal cost/quality
     * trade-offs at a glance.
     *
     * @param array $presets   Full preset definitions.
     * @param array $allowed   Subset of presets the teacher may choose.
     * @return string HTML string.
     */
    private function build_summary_table(array $presets, array $allowed): string {

        // Colour coding: green = lightest load, red = heaviest.
        $colours = ['light' => '#d4edda', 'standard' => '#fff3cd', 'full' => '#f8d7da'];

        $headers = [
            get_string('preset_col_history_chars',    'block_mistralagent'),
            get_string('preset_col_filecontent_chars', 'block_mistralagent'),
            get_string('preset_col_rag_chunks',        'block_mistralagent'),
            get_string('preset_col_history_messages',  'block_mistralagent'),
        ];

        $html  = '<div class="alert alert-info mb-3">';
        $html .= '<strong>' . get_string('config_summary_title', 'block_mistralagent') . '</strong>';
        $html .= '<p class="mb-2">' . get_string('config_summary_intro', 'block_mistralagent') . '</p>';

        $html .= '<table class="table table-bordered table-sm mb-0" style="font-size:.9rem">';
        $html .= '<thead><tr>';
        $html .= '<th>' . get_string('preset_col_name', 'block_mistralagent') . '</th>';
        foreach ($headers as $h) {
            $html .= '<th>' . $h . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($allowed as $key => $label) {
            $p   = $presets[$key];
            $bg  = $colours[$key] ?? '#ffffff';
            $html .= "<tr style=\"background:{$bg}\">";
            $html .= "<td><strong>{$label}</strong></td>";
            $html .= '<td>' . number_format($p['history_chars'] / 1000, 0) . ' K cars</td>';
            $html .= '<td>' . number_format($p['filecontent_chars'] / 1000, 0) . ' K cars</td>';
            $html .= '<td>' . $p['rag_chunks'] . ' ' . get_string('chunks', 'block_mistralagent') . '</td>';
            $html .= '<td>' . $p['history_messages'] . ' ' . get_string('messages', 'block_mistralagent') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        // Consequence explanations below the table.
        $html .= '<ul class="mt-2 mb-0" style="font-size:.85rem">';
        $html .= '<li>' . get_string('config_consequence_history_chars',    'block_mistralagent') . '</li>';
        $html .= '<li>' . get_string('config_consequence_filecontent_chars', 'block_mistralagent') . '</li>';
        $html .= '<li>' . get_string('config_consequence_rag_chunks',        'block_mistralagent') . '</li>';
        $html .= '<li>' . get_string('config_consequence_history_messages',  'block_mistralagent') . '</li>';
        $html .= '</ul>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Validate that the chosen preset does not exceed the admin ceiling.
     *
     * @param array $data  Form data.
     * @param array $files Uploaded files (unused).
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files) {
        $errors    = parent::validation($data, $files);
        $order     = self::preset_order();
        $maxpreset = get_config('block_mistralagent', 'max_preset') ?: 'standard';
        $maxindex  = array_search($maxpreset, $order);
        $chosen    = $data['config_preset'] ?? 'standard';
        $chosenidx = array_search($chosen, $order);

        if ($chosenidx === false || $chosenidx > $maxindex) {
            $errors['config_preset'] = get_string('preset_exceeds_max', 'block_mistralagent',
                get_string('preset_' . $maxpreset, 'block_mistralagent'));
        }

        return $errors;
    }
}
