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
 * List the current user conversations for a course.
 *
 * @package    block_mistralagent
 * @copyright  2026 Miguël Dhyne <miguel.dhyne@gmail.com>
 * @author     Miguël Dhyne <miguel.dhyne@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
use block_mistralagent\manager;

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$convid = optional_param('convid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

$context = context_course::instance($courseid);
require_capability('block/mistralagent:use', $context);

$PAGE->set_url(new moodle_url('/blocks/mistralagent/myconversations.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('myconversations', 'block_mistralagent'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// Handle delete action.
if ($action === 'delete' && $convid && confirm_sesskey()) {
    // Verify ownership.
    $conv = $DB->get_record('block_mistralagent_convs', ['id' => $convid, 'userid' => $USER->id, 'courseid' => $courseid]);
    if ($conv) {
        $DB->delete_records('block_mistralagent_msgs', ['conversationid' => $convid]);
        $DB->delete_records('block_mistralagent_convs', ['id' => $convid]);
    }
    redirect($PAGE->url, get_string('conversationdeleted', 'block_mistralagent'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('myconversations', 'block_mistralagent'));

// Get user's conversations for this course.
$conversations = $DB->get_records_sql(
    "SELECT c.*,
            (SELECT COUNT(*) FROM {block_mistralagent_msgs} m WHERE m.conversationid = c.id) AS messagecount,
            (SELECT content FROM {block_mistralagent_msgs} m
                WHERE m.conversationid = c.id ORDER BY m.timecreated ASC LIMIT 1) AS firstmessage
     FROM {block_mistralagent_convs} c
     WHERE c.userid = ? AND c.courseid = ?
     ORDER BY c.timemodified DESC",
    [$USER->id, $courseid]
);

if (empty($conversations)) {
    echo $OUTPUT->notification(get_string('noconversationsyet', 'block_mistralagent'), 'info');

    // Button to start new conversation.
    $chaturl = new moodle_url('/blocks/mistralagent/chat.php', ['courseid' => $courseid]);
    echo html_writer::tag(
        'p',
        html_writer::link(
            $chaturl,
            get_string('startnewconversation', 'block_mistralagent'),
            ['class' => 'btn btn-primary']
        )
    );
} else {
    // Button to start new conversation.
    $chaturl = new moodle_url('/blocks/mistralagent/chat.php', ['courseid' => $courseid, 'newconv' => 1]);
    echo html_writer::tag(
        'p',
        html_writer::link(
            $chaturl,
            get_string('startnewconversation', 'block_mistralagent'),
            ['class' => 'btn btn-primary']
        )
    );

    echo '<div class="list-group mt-3">';

    foreach ($conversations as $conv) {
        // Create a preview of the conversation.
        $preview = '';
        if (!empty($conv->firstmessage)) {
            $preview = shorten_text(strip_tags($conv->firstmessage), 100);
        }

        $convdate = userdate($conv->timecreated, get_string('strftimedatetime'));
        $title = $conv->title ?: get_string('conversationfrom', 'block_mistralagent', $convdate);

        $continueurl = new moodle_url(
            '/blocks/mistralagent/chat.php',
            ['courseid' => $courseid, 'convid' => $conv->id]
        );
        $deleteurl = new moodle_url(
            $PAGE->url,
            ['action' => 'delete', 'convid' => $conv->id, 'sesskey' => sesskey()]
        );

        echo '<div class="list-group-item list-group-item-action flex-column align-items-start">';
        echo '<div class="d-flex w-100 justify-content-between">';
        echo '<h5 class="mb-1">' . html_writer::link($continueurl, s($title)) . '</h5>';
        echo '<small class="text-muted">' . get_string('nmessages', 'block_mistralagent', $conv->messagecount) . '</small>';
        echo '</div>';

        if (!empty($preview)) {
            echo '<p class="mb-1 text-muted">' . s($preview) . '</p>';
        }

        echo '<div class="d-flex justify-content-between align-items-center mt-2">';
        $lastactivity = userdate($conv->timemodified, get_string('strftimedatetime'));
        echo '<small class="text-muted">'
            . get_string('lastactivity', 'block_mistralagent') . ': ' . $lastactivity . '</small>';
        echo '<div>';
        echo html_writer::link(
            $continueurl,
            get_string('continue', 'block_mistralagent'),
            ['class' => 'btn btn-sm btn-primary mr-2']
        );
        echo html_writer::link(
            $deleteurl,
            get_string('delete'),
            [
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => "return confirm('" . get_string('confirmdeleteconversation', 'block_mistralagent') . "');",
            ]
        );
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
}

echo html_writer::link(
    new moodle_url('/course/view.php', ['id' => $courseid]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);

echo $OUTPUT->footer();
