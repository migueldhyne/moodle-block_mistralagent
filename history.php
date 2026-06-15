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
 * Conversation history page (teacher/admin view).
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use block_mistralagent\manager;

// Parameters.

$courseid        = required_param('courseid', PARAM_INT);
$blockinstanceid = optional_param('blockinstanceid', 0, PARAM_INT);
$userid          = optional_param('userid', 0, PARAM_INT);

// Access control.

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

$context = context_course::instance($courseid);
require_capability('block/mistralagent:viewconversations', $context);

// Page setup.

$PAGE->set_url(new moodle_url('/blocks/mistralagent/history.php', [
    'courseid'        => $courseid,
    'blockinstanceid' => $blockinstanceid,
]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('conversationhistory', 'block_mistralagent'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// Enrolled users (used for both the filter form and userid validation).

// Moodle 4.4+ exige tous les champs de nom pour fullname().
$enrolledusers = get_enrolled_users(
    $context,
    'block/mistralagent:use',
    0,
    'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email'
);

/*
 * Validate $userid: reject any value that is not in the enrolled users list. Although viewconversations is a
 * teacher/admin capability, passing an arbitrary userid could leak conversation metadata for users outside the
 * course (e.g. if manager::get_course_conversations() does not filter by enrolment).  Resetting to 0 silently
 * falls back to "all users".
 */
if ($userid > 0 && !isset($enrolledusers[$userid])) {
    $userid = 0;
}

// Output.

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('conversationhistory', 'block_mistralagent'));

/*
 * User filter form. The onchange auto-submit is kept as a minimal inline handler — this page has no AMD
 * module and the behaviour is a standard Moodle pattern for simple filter forms.
 */

$useroptions = [0 => get_string('allusers', 'block_mistralagent')];
foreach ($enrolledusers as $user) {
    $useroptions[$user->id] = fullname($user);
}

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blockinstanceid', 'value' => $blockinstanceid]);
echo html_writer::tag(
    'label',
    get_string('student', 'block_mistralagent') . ' : ',
    ['class' => 'mr-2', 'for' => 'mistralagent-userid-select']
);
echo html_writer::select(
    $useroptions,
    'userid',
    $userid,
    null,
    [
        'id'       => 'mistralagent-userid-select',
        'class'    => 'form-control mr-2',
        'onchange' => 'this.form.submit()',
    ]
);
echo html_writer::end_tag('form');

// Conversations table.

/*
 * Pass null to get all users, or a specific userid for the filter. $userid is already validated against
 * $enrolledusers above.
 */
$filtereduserid = $userid > 0 ? $userid : null;

if ($blockinstanceid > 0) {
    // Block-filtered view — uses the v2 method by blockinstanceid.
    $conversations = manager::get_instance_conversations($blockinstanceid, $filtereduserid);
} else {
    /*
     * No block specified — display all course conversations aggregated from all
     * instances du bloc dans ce cours.
     */
    $sql = "SELECT c.*, u.firstname, u.lastname,
                   u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                   (SELECT COUNT(*) FROM {block_mistralagent_msgs} m WHERE m.conversationid = c.id) AS messagecount
            FROM {block_mistralagent_convs} c
            JOIN {user} u ON u.id = c.userid
            WHERE c.courseid = :courseid"
            . ($filtereduserid ? " AND c.userid = :userid" : "")
            . " ORDER BY c.timemodified DESC";
    $sqlparams = ['courseid' => $courseid];
    if ($filtereduserid) {
        $sqlparams['userid'] = $filtereduserid;
    }
    $conversations = $DB->get_records_sql($sql, $sqlparams);
}

if (empty($conversations)) {
    echo $OUTPUT->notification(get_string('noconversations', 'block_mistralagent'), 'info');
} else {
    $table                      = new html_table();
    $table->head                = [
        get_string('student', 'block_mistralagent'),
        get_string('messages', 'block_mistralagent'),
        get_string('lastactivity', 'block_mistralagent'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'table table-striped';

    foreach ($conversations as $conv) {
        // Normalise missing name fields to avoid Moodle 4.4+ warnings.
        foreach (['firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename'] as $field) {
            if (!isset($conv->$field)) {
                $conv->$field = '';
            }
        }
        $viewurl = new moodle_url('/blocks/mistralagent/viewconversation.php', [
            'id'       => $conv->id,
            'courseid' => $courseid,
        ]);

        $table->data[] = [
            fullname($conv),
            (int)$conv->messagecount,
            userdate($conv->timemodified),
            html_writer::link(
                $viewurl,
                get_string('viewconversation', 'block_mistralagent'),
                ['class' => 'btn btn-sm btn-outline-primary']
            ),
        ];
    }

    echo html_writer::table($table);
}

echo html_writer::link(
    new moodle_url('/course/view.php', ['id' => $courseid]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);

echo $OUTPUT->footer();
