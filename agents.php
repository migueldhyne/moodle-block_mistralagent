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
 * Agent management page.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_mistralagent\manager;
use block_mistralagent\mistral_client;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('block_mistralagent_agents');
require_capability('block/mistralagent:manageagents', context_system::instance());

$PAGE->set_url(new moodle_url('/blocks/mistralagent/agents.php'));
$PAGE->set_title(get_string('manageagents', 'block_mistralagent'));
$PAGE->set_heading(get_string('manageagents', 'block_mistralagent'));

// Fetch agents from the Mistral API (add form).
$fetcherror    = '';
$fetchedagents = [];

if ($action === 'add'
        && optional_param('fetch_agents', false, PARAM_BOOL)
        && confirm_sesskey()) {

    // PARAM_RAW is required: Mistral API keys contain mixed alphanumeric characters and
    // special chars that would be stripped by PARAM_ALPHANUM or PARAM_TEXT.
    // The value is never output to HTML — it is only transmitted to the Mistral API over HTTPS.
    $apikeyinput = trim(optional_param('apikey_input', '', PARAM_RAW));

    // Fall back to the global admin key if the field is empty.
    if (empty($apikeyinput)) {
        $apikeyinput = get_config('block_mistralagent', 'apikey');
    }

    if (empty($apikeyinput)) {
        $fetcherror = get_string('apikey_empty', 'block_mistralagent');
    } else {
        try {
            $fetchedagents = mistral_client::list_agents_for_key($apikeyinput);
            if (empty($fetchedagents)) {
                $fetcherror = get_string('no_agents_found', 'block_mistralagent');
            }
        } catch (\Exception $e) {
            $fetcherror = $e->getMessage();
        }
    }
}

// Handle form submission.
if ($action === 'save' && confirm_sesskey()) {
    $name = required_param('name', PARAM_TEXT);
    $agentid = required_param('agent_id', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    $enabled = optional_param('enabled', 0, PARAM_INT);

    if ($id > 0) {
        manager::update_agent($id, $name, $agentid, $description, (bool)$enabled);
    } else {
        manager::create_agent($name, $agentid, $description);
    }

    redirect(new moodle_url('/blocks/mistralagent/agents.php'), get_string('agentsaved', 'block_mistralagent'));
}

// Handle delete.
if ($action === 'delete' && $id > 0 && confirm_sesskey()) {
    if (manager::delete_agent($id)) {
        redirect(new moodle_url('/blocks/mistralagent/agents.php'), get_string('agentdeleted', 'block_mistralagent'));
    } else {
        redirect(
            new moodle_url('/blocks/mistralagent/agents.php'),
            get_string('agentinuse', 'block_mistralagent'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->header();

$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'blocksettingmistralagent']);
echo html_writer::div(
    html_writer::link($settingsurl, get_string('backtosettings', 'block_mistralagent'), ['class' => 'btn btn-secondary']),
    'mb-3'
);

// Show add/edit form.
if ($action === 'add' || $action === 'edit') {
    $agent = null;
    if ($action === 'edit' && $id > 0) {
        $agent = manager::get_agent($id);
        if (!$agent) {
            throw new moodle_exception('agentnotfound', 'block_mistralagent');
        }
    }

    // For action=add: API fetch section.
    if ($action === 'add') {

        $adminapikey = get_config('block_mistralagent', 'apikey');
        $hasadminkey = !empty($adminapikey);

        echo html_writer::tag('h5',
            '<i class="fa fa-search mr-2"></i>' . get_string('fetch_agents', 'block_mistralagent'),
            ['class' => 'mb-3']);

        echo '<div class="card mb-4">';
        echo '<div class="card-header"><strong>'
            . '<i class="fa fa-key mr-1"></i>'
            . get_string('step1_apikey', 'block_mistralagent')
            . '</strong></div>';
        echo '<div class="card-body">';

        if ($hasadminkey) {
            // Admin key already configured — offer to use it directly.
            echo '<div class="alert alert-info mb-3">'
                . '<i class="fa fa-info-circle mr-1"></i>'
                . get_string('admin_apikey_configured', 'block_mistralagent')
                . '</div>';

            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mb-3',
                'action' => new moodle_url('/blocks/mistralagent/agents.php', ['action' => 'add'])]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'apikey_input', 'value' => '']);
            echo '<button type="submit" name="fetch_agents" value="1" class="btn btn-primary mr-2">'
                . '<i class="fa fa-search mr-1"></i>'
                . get_string('fetch_agents_saved', 'block_mistralagent') . '</button>';
            echo html_writer::end_tag('form');

            echo '<hr><p class="text-muted small mb-2">'
                . get_string('use_different_key', 'block_mistralagent') . '</p>';
        }

        // Form to enter a different API key.
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mform',
            'action' => new moodle_url('/blocks/mistralagent/agents.php', ['action' => 'add'])]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        echo html_writer::start_div('form-group row');
        echo html_writer::tag('label', get_string('own_apikey_label', 'block_mistralagent'),
            ['class' => 'col-sm-3 col-form-label', 'for' => 'apikey-input']);
        echo html_writer::start_div('col-sm-9');
        echo '<div class="input-group">';
        echo '<input type="password" name="apikey_input" id="apikey-input" class="form-control"'
            . ' autocomplete="new-password" placeholder=""'
            . (!$hasadminkey ? ' required' : '') . '>';
        echo '<div class="input-group-append">';
        echo '<button type="button" class="btn btn-outline-secondary" id="btn-toggle-key">'
            . '<i class="fa fa-eye" id="eye-icon"></i></button>';
        echo '</div></div>';
        echo '<small class="form-text text-muted">' . get_string('own_apikey_hint', 'block_mistralagent') . '</small>';
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('form-group row');
        echo html_writer::start_div('col-sm-9 offset-sm-3');
        echo '<button type="submit" name="fetch_agents" value="1" class="btn btn-outline-primary">'
            . '<i class="fa fa-search mr-1"></i>'
            . get_string('fetch_agents', 'block_mistralagent') . '</button>';
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
        echo '</div></div>'; // End card-body, card.

        // Fetch error.
        if (!empty($fetcherror)) {
            echo '<div class="alert alert-danger">'
                . '<i class="fa fa-exclamation-triangle mr-1"></i>'
                . htmlspecialchars($fetcherror) . '</div>';
        }

        // Agents fetched successfully: one-click selection.
        if (!empty($fetchedagents)) {
            echo '<div class="card border-success mb-4">';
            echo '<div class="card-header bg-success text-white">'
                . '<strong><i class="fa fa-robot mr-1"></i>'
                . get_string('step2_selectagent', 'block_mistralagent') . '</strong>'
                . ' <small>(' . count($fetchedagents) . ' '
                . get_string('agents_found', 'block_mistralagent') . ')</small></div>';
            echo '<div class="card-body">';

            echo html_writer::start_tag('form', ['method' => 'post',
                'action' => new moodle_url('/blocks/mistralagent/agents.php', ['action' => 'save', 'id' => 0]),
                'class' => 'mform']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input',
                ['type' => 'hidden', 'name' => 'name', 'id' => 'hidden-agent-name', 'value' => '']);
            echo html_writer::empty_tag('input',
                ['type' => 'hidden', 'name' => 'agent_id', 'id' => 'hidden-agent-id', 'value' => '']);
            echo html_writer::empty_tag('input',
                ['type' => 'hidden', 'name' => 'description', 'id' => 'hidden-agent-desc', 'value' => '']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enabled', 'value' => '1']);

            echo '<div class="row">';
            foreach ($fetchedagents as $ag) {
                $agid   = $ag['id'] ?? '';
                $agname = $ag['name'] ?? $agid;
                $agdesc = $ag['description'] ?? '';

                echo '<div class="col-md-6 mb-3">';
                echo '<div class="card h-100 agent-card" style="cursor:pointer;transition:all .15s"'
                    . ' data-agent-id="'   . htmlspecialchars($agid)   . '"'
                    . ' data-agent-name="' . htmlspecialchars($agname) . '"'
                    . ' data-agent-desc="' . htmlspecialchars($agdesc) . '">';
                echo '<div class="card-body">';
                echo '<h6 class="card-title mb-1"><i class="fa fa-robot mr-1 text-primary"></i>'
                    . htmlspecialchars($agname) . '</h6>';
                if ($agdesc) {
                    echo '<p class="card-text text-muted small mb-2">' . htmlspecialchars($agdesc) . '</p>';
                }
                echo '<code class="small text-muted">' . htmlspecialchars($agid) . '</code>';
                echo '</div></div></div>';
            }
            echo '</div>';

            echo '<div id="agent-selected-info" class="alert alert-primary mt-3" style="display:none">'
                . '<i class="fa fa-check mr-1"></i>'
                . get_string('agent_selected', 'block_mistralagent') . ' : '
                . '<strong id="selected-agent-label"></strong></div>';

            echo html_writer::start_div('mt-3');
            echo '<button type="submit" class="btn btn-success btn-lg" id="btn-save-agent" disabled>'
                . '<i class="fa fa-save mr-1"></i>'
                . get_string('savechanges') . '</button>';
            echo ' ';
            echo html_writer::link(new moodle_url('/blocks/mistralagent/agents.php'),
                get_string('cancel'), ['class' => 'btn btn-secondary btn-lg']);
            echo html_writer::end_div();
            echo html_writer::end_tag('form');
            echo '</div></div>';
        }

        echo '<hr><h5 class="mb-3"><i class="fa fa-pencil mr-2"></i>'
            . get_string('addagent_manual', 'block_mistralagent') . '</h5>';
    } // End of the action=add fetch section.

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/blocks/mistralagent/agents.php', ['action' => 'save', 'id' => $id]),
        'class' => 'mform',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('agentname', 'block_mistralagent'),
        ['class' => 'col-sm-3 col-form-label', 'for' => 'name']);
    echo html_writer::start_div('col-sm-9');
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'name', 'id' => 'name', 'class' => 'form-control',
        'value' => $agent ? $agent->name : '', 'required' => true,
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('agentid', 'block_mistralagent'),
        ['class' => 'col-sm-3 col-form-label', 'for' => 'agent_id']);
    echo html_writer::start_div('col-sm-9');
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'agent_id', 'id' => 'agent_id', 'class' => 'form-control',
        'value' => $agent ? $agent->agent_id : '', 'placeholder' => 'ag_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'required' => true,
    ]);
    echo html_writer::tag('small', get_string('agentid_help', 'block_mistralagent'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', get_string('agentdescription', 'block_mistralagent'),
        ['class' => 'col-sm-3 col-form-label', 'for' => 'description']);
    echo html_writer::start_div('col-sm-9');
    echo html_writer::tag('textarea', $agent ? $agent->description : '',
        ['name' => 'description', 'id' => 'description', 'class' => 'form-control', 'rows' => 3]);
    echo html_writer::end_div();
    echo html_writer::end_div();

    if ($agent) {
        echo html_writer::start_div('form-group row');
        echo html_writer::tag('label', get_string('agentenabled', 'block_mistralagent'), ['class' => 'col-sm-3 col-form-label']);
        echo html_writer::start_div('col-sm-9');
        echo html_writer::checkbox('enabled', 1, $agent->enabled, '');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    echo html_writer::start_div('form-group row');
    echo html_writer::start_div('col-sm-9 offset-sm-3');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('savechanges'), 'class' => 'btn btn-primary']);
    echo ' ';
    echo html_writer::link(new moodle_url('/blocks/mistralagent/agents.php'),
        get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
} else {
    // Show agents list.
    echo html_writer::tag('p', html_writer::link(
        new moodle_url('/blocks/mistralagent/agents.php', ['action' => 'add']),
        get_string('addagent', 'block_mistralagent'),
        ['class' => 'btn btn-primary']
    ));

    $agents = manager::get_agents(false);

    if (empty($agents)) {
        echo $OUTPUT->notification(get_string('noagents', 'block_mistralagent'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('agentname', 'block_mistralagent'),
            get_string('agentid', 'block_mistralagent'),
            get_string('agentdescription', 'block_mistralagent'),
            get_string('agentenabled', 'block_mistralagent'),
            get_string('actions'),
        ];
        $table->attributes['class'] = 'table table-striped';

        foreach ($agents as $agent) {
            $actions = [];
            $editurl = new moodle_url('/blocks/mistralagent/agents.php',
                ['action' => 'edit', 'id' => $agent->id]);
            $actions[] = html_writer::link($editurl, $OUTPUT->pix_icon('t/edit', get_string('edit')));
            $deleteurl = new moodle_url('/blocks/mistralagent/agents.php',
                ['action' => 'delete', 'id' => $agent->id, 'sesskey' => sesskey()]);
            $deleteconfirm = get_string('confirmdeleteagent', 'block_mistralagent');
            $actions[] = html_writer::link($deleteurl,
                $OUTPUT->pix_icon('t/delete', get_string('delete')),
                ['onclick' => "return confirm('" . $deleteconfirm . "');"]);

            $table->data[] = [
                s($agent->name),
                html_writer::tag('code', s($agent->agent_id)),
                s($agent->description),
                $agent->enabled ? $OUTPUT->pix_icon('t/check', get_string('yes')) : $OUTPUT->pix_icon('t/block', get_string('no')),
                implode(' ', $actions),
            ];
        }

        echo html_writer::table($table);
    }
}

$PAGE->requires->js_amd_inline('
require(["jquery"], function ($) {
    $("#btn-toggle-key").on("click", function() {
        var inp = $("#apikey-input");
        var eye = $("#eye-icon");
        if (inp.attr("type") === "password") {
            inp.attr("type", "text");
            eye.removeClass("fa-eye").addClass("fa-eye-slash");
        } else {
            inp.attr("type", "password");
            eye.removeClass("fa-eye-slash").addClass("fa-eye");
        }
    });

    $(".agent-card").on("click", function() {
        $(".agent-card").removeClass("border-primary bg-light");
        $(this).addClass("border-primary bg-light");
        var id   = $(this).data("agent-id");
        var name = $(this).data("agent-name");
        var desc = $(this).data("agent-desc") || "";
        $("#hidden-agent-id").val(id);
        $("#hidden-agent-name").val(name);
        $("#hidden-agent-desc").val(desc);
        $("#selected-agent-label").text(name);
        $("#agent-selected-info").show();
        $("#btn-save-agent").prop("disabled", false);
    });
});
');

echo $OUTPUT->footer();
