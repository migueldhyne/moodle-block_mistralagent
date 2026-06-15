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
 * Context preset manager for block_mistralagent.
 *
 * Extracted from block_mistralagent (block_base subclass) so that it is
 * reachable via Moodle's PSR-4 autoloader during AJAX external-function
 * calls, where block_mistralagent.php is never included.
 *
 * Both block_mistralagent::get_content() and the external send_message
 * function use block_mistralagent\preset_manager::resolve_preset().
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_mistralagent;

/**
 * Manages context presets (Light / Standard / Full).
 *
 * Preset values control four limits applied when building the payload
 * sent to the Mistral API:
 *   - history_chars      Maximum characters of conversation history.
 *   - filecontent_chars  Maximum characters of attached file content.
 *   - rag_chunks         Maximum number of RAG document chunks injected.
 *   - history_messages   Maximum number of past messages kept.
 */
class preset_manager {
    /**
     * Return the fixed definition table for all presets.
     *
     * @return array  Keyed by preset name ('light', 'standard', 'full').
     */
    public static function get_preset_definitions(): array {
        return [
            'light' => [
                'history_chars'     => 10000,
                'filecontent_chars' => 50000,
                'rag_chunks'        => 3,
                'history_messages'  => 10,
            ],
            'standard' => [
                'history_chars'     => 30000,
                'filecontent_chars' => 200000,
                'rag_chunks'        => 5,
                'history_messages'  => 20,
            ],
            'full' => [
                'history_chars'     => 60000,
                'filecontent_chars' => 400000,
                'rag_chunks'        => 8,
                'history_messages'  => 40,
            ],
        ];
    }

    /**
     * Resolve the effective preset values for a block instance.
     *
     * Reads the preset stored in the instance config ($this->config in the
     * block class, or an unserialized configdata record from the DB).
     * Falls back to 'standard' when no config has been saved yet.
     *
     * Enforces the admin ceiling silently: if the admin lowered max_preset
     * after the teacher had already saved a higher preset, the result is
     * capped to the current maximum without surfacing an error.
     *
     * @param \stdClass|null $instanceconfig  Block instance config object.
     * @return array  Keys: history_chars, filecontent_chars, rag_chunks, history_messages.
     */
    public static function resolve_preset(?\stdClass $instanceconfig): array {
        $presets   = self::get_preset_definitions();
        $order     = ['light', 'standard', 'full'];

        $maxpreset = get_config('block_mistralagent', 'max_preset') ?: 'standard';
        $maxindex  = array_search($maxpreset, $order);
        if ($maxindex === false) {
            $maxindex = 1; // Fallback to 'standard'.
        }

        $chosen    = $instanceconfig->preset ?? 'standard';
        $chosenidx = array_search($chosen, $order);

        // Cap to admin maximum.
        if ($chosenidx === false || $chosenidx > $maxindex) {
            $chosen = $order[$maxindex];
        }

        return $presets[$chosen];
    }
}
