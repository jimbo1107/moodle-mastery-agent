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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * List every mastery agent activity in a course.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = get_course($id);
require_login($course);

$context = context_course::instance($course->id);

$PAGE->set_url('/mod/masteryagent/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_masteryagent'));

$instances = get_all_instances_in_course('masteryagent', $course);

if (empty($instances)) {
    echo $OUTPUT->notification(get_string('noinstances', 'moodle'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [get_string('name'), get_string('lessonquestion', 'mod_masteryagent')];
$table->attributes['class'] = 'table generaltable';

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/masteryagent/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name),
        ['class' => $instance->visible ? '' : 'dimmed']
    );
    $table->data[] = [$link, s($instance->lessonid . ' ' . $instance->lessontitle)];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
