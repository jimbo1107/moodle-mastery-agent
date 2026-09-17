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
 * The learner-facing assessment conversation.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/masteryagent/lib.php');

use mod_masteryagent\attempt;
use mod_masteryagent\sequence;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('masteryagent', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('masteryagent', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/masteryagent:view', $context);

$PAGE->set_url('/mod/masteryagent/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($instance);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$canattempt = has_capability('mod/masteryagent:attempt', $context);
$canreport = has_capability('mod/masteryagent:viewreports', $context);
$error = null;

$sequence = sequence::from_instance($instance);

if ($sequence->count() === 0) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($instance->name));
    echo $OUTPUT->notification(get_string('nolessonloaded', 'mod_masteryagent'), 'error');
    echo $OUTPUT->footer();
    exit;
}

$current = attempt::get_latest($instance, (int) $USER->id);

if ($canattempt && $action !== '' && confirm_sesskey()) {
    if ($action === 'start') {
        $allowed = $current === null
            || ($current->is_finished() && !empty($instance->allowretry));
        if ($allowed) {
            $current = attempt::start($instance, (int) $USER->id, $sequence);
        }
    } else if ($action === 'reply' && $current !== null && !$current->is_finished()) {
        $reply = trim(required_param('reply', PARAM_RAW));
        if ($reply !== '') {
            try {
                $current->submit($reply, $sequence, (int) $context->id);
            } catch (moodle_exception $e) {
                $error = $e->getMessage();
            }
            $current = attempt::get_latest($instance, (int) $USER->id);
        }
    } else if ($action === 'finish' && $current !== null && !$current->is_finished()) {
        try {
            $current->finish_now($sequence, (int) $context->id);
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
        $current = attempt::get_latest($instance, (int) $USER->id);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('masteryagent', $instance, $cm->id), 'generalbox', 'intro');
}

if ($error !== null) {
    echo $OUTPUT->notification($error, 'error');
}

if ($canreport) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/masteryagent/report.php', ['id' => $cm->id]),
            get_string('viewreport', 'mod_masteryagent'),
            ['class' => 'btn btn-secondary']
        ),
        'mb-3'
    );
}

if (!$canattempt) {
    $list = '';
    foreach ($sequence->all() as $lesson) {
        $list .= html_writer::tag(
            'li',
            html_writer::tag('strong', s(trim($lesson->lesson_id() . ' ' . $lesson->title())))
            . html_writer::tag('div', s($lesson->question_text()))
        );
    }
    echo $OUTPUT->box(
        html_writer::tag('h4', get_string('lessonsinthisactivity', 'mod_masteryagent', $sequence->count()))
        . html_writer::tag('ul', $list),
        'generalbox'
    );
    echo $OUTPUT->footer();
    exit;
}

/**
 * Render the final assessment for one lesson.
 *
 * @param array $result Stored per-lesson result.
 * @return string
 */
function masteryagent_render_lesson_result(array $result): string {
    $heading = trim(($result['lesson_id'] ?? '') . ' ' . ($result['title'] ?? ''));
    $out = html_writer::tag('h5', s($heading) . ' — ' . s((string) ($result['score'] ?? '')) . '/'
        . (int) ($result['max'] ?? 0));
    $out .= html_writer::tag('p', nl2br(s((string) ($result['summary'] ?? ''))));

    $verdictmap = [
        'met' => 'verdictmetshort',
        'partial' => 'verdictpartial',
        'notmet' => 'verdictnotmetshort',
    ];
    $rows = '';
    foreach ((array) ($result['dimensions'] ?? []) as $dimension) {
        if (!is_array($dimension)) {
            continue;
        }
        $raw = preg_replace('/[^a-z]/', '', strtolower((string) ($dimension['verdict'] ?? '')));
        $label = isset($verdictmap[$raw])
            ? get_string($verdictmap[$raw], 'mod_masteryagent')
            : s((string) ($dimension['verdict'] ?? ''));
        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('td', s((string) ($dimension['id'] ?? '')))
            . html_writer::tag('td', $label)
            . html_writer::tag('td', s((string) ($dimension['comment'] ?? '')))
        );
    }
    if ($rows !== '') {
        $out .= html_writer::tag(
            'table',
            html_writer::tag(
                'thead',
                html_writer::tag(
                    'tr',
                    html_writer::tag('th', get_string('dimension', 'mod_masteryagent'))
                    . html_writer::tag('th', get_string('verdict', 'mod_masteryagent'))
                    . html_writer::tag('th', get_string('comment', 'mod_masteryagent'))
                )
            ) . html_writer::tag('tbody', $rows),
            ['class' => 'table table-sm']
        );
    }

    if (!empty($result['next_step'])) {
        $out .= html_writer::tag('p', html_writer::tag('strong', get_string('nextstep', 'mod_masteryagent'))
            . ' ' . s((string) $result['next_step']));
    }

    return $out;
}

if ($current === null) {
    $blurb = $sequence->is_multi()
        ? get_string('introblurbmulti', 'mod_masteryagent', (object) [
            'lessons' => $sequence->count(),
            'turns' => (int) $instance->maxturns,
        ])
        : get_string('introblurb', 'mod_masteryagent', (int) $instance->maxturns);

    echo $OUTPUT->box(
        html_writer::tag('p', $blurb)
        . html_writer::div(
            html_writer::link(
                new moodle_url('/mod/masteryagent/view.php', [
                    'id' => $cm->id,
                    'action' => 'start',
                    'sesskey' => sesskey(),
                ]),
                get_string('begin', 'mod_masteryagent'),
                ['class' => 'btn btn-primary']
            )
        ),
        'generalbox'
    );
    echo $OUTPUT->footer();
    exit;
}

// Progress through the sequence.
if (!$current->is_finished() && $sequence->is_multi()) {
    $lesson = $sequence->get($current->lesson_index());
    echo $OUTPUT->box(
        html_writer::tag('strong', get_string('progress', 'mod_masteryagent', (object) [
            'position' => $current->lesson_index() + 1,
            'total' => $sequence->count(),
        ]))
        . ' ' . s($lesson === null ? '' : trim($lesson->lesson_id() . ' ' . $lesson->title())),
        'generalbox py-2'
    );
}

// The conversation.
echo html_writer::start_div('masteryagent-conversation');
foreach ($current->messages() as $message) {
    $isagent = $message->role === 'agent';
    $label = $isagent
        ? get_string('roleagent', 'mod_masteryagent')
        : get_string('rolestudent', 'mod_masteryagent');
    echo html_writer::div(
        html_writer::tag('div', $label, ['class' => 'masteryagent-role'])
        . html_writer::tag('div', nl2br(s($message->message)), ['class' => 'masteryagent-text']),
        'masteryagent-message ' . ($isagent ? 'masteryagent-agent' : 'masteryagent-student')
    );
}
echo html_writer::end_div();

if (!$current->is_finished()) {
    echo html_writer::tag(
        'p',
        get_string('turnsleft', 'mod_masteryagent', $current->turns_left()),
        ['class' => 'text-muted']
    );

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/masteryagent/view.php'),
        'class' => 'masteryagent-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reply']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('textarea', '', [
        'name' => 'reply',
        'rows' => 8,
        'class' => 'form-control',
        'required' => 'required',
        'placeholder' => get_string('replyplaceholder', 'mod_masteryagent'),
    ]);
    echo html_writer::div(
        html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-primary mt-2',
            'value' => get_string('sendreply', 'mod_masteryagent'),
        ]),
        'mt-2'
    );
    echo html_writer::end_tag('form');

    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/masteryagent/view.php', [
                'id' => $cm->id,
                'action' => 'finish',
                'sesskey' => sesskey(),
            ]),
            get_string('finishnow', 'mod_masteryagent'),
            ['class' => 'btn btn-link']
        ),
        'mt-2'
    );
} else {
    $record = $current->get_record();
    $results = $current->lesson_results();

    $body = html_writer::tag('h4', get_string('scoreline', 'mod_masteryagent', (object) [
        'score' => format_float((float) $record->score, 0),
        'max' => masteryagent_total_grade($instance),
    ]));

    if ($sequence->is_multi()) {
        $body .= html_writer::tag('p', get_string('lessonsmastered', 'mod_masteryagent', (object) [
            'mastered' => $current->lessons_mastered(),
            'total' => count($results),
        ]), ['class' => 'lead']);
    } else {
        $body .= html_writer::tag('p', $current->met_threshold()
            ? get_string('verdictmet', 'mod_masteryagent')
            : get_string('verdictnotmet', 'mod_masteryagent', (int) $instance->threshold), ['class' => 'lead']);
    }

    if (!empty($instance->provisional)) {
        $body .= html_writer::div(get_string('provisionalbanner', 'mod_masteryagent'), 'alert alert-info');
    }

    $body .= html_writer::tag('p', nl2br(s((string) $record->summary)));
    echo $OUTPUT->box($body, 'generalbox');

    foreach ($results as $result) {
        echo $OUTPUT->box(masteryagent_render_lesson_result($result), 'generalbox');
    }

    if (!empty($instance->allowretry)) {
        echo html_writer::div(
            html_writer::link(
                new moodle_url('/mod/masteryagent/view.php', [
                    'id' => $cm->id,
                    'action' => 'start',
                    'sesskey' => sesskey(),
                ]),
                get_string('tryagain', 'mod_masteryagent'),
                ['class' => 'btn btn-primary']
            ),
            'mt-2'
        );
    }
}

echo $OUTPUT->footer();
