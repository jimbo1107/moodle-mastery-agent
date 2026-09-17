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

namespace mod_masteryagent\output;

use html_writer;
use moodle_url;
use mod_masteryagent\attempt;
use mod_masteryagent\conversation;
use mod_masteryagent\sequence;

/**
 * Shared learner-only HTML for the initial page and AJAX responses.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_view {
    /**
     * Render the current conversation without exposing the evidence ledger or rubric.
     *
     * @param \stdClass $instance Activity record.
     * @param \stdClass $cm Course module record.
     * @param sequence $sequence Selected lessons.
     * @param attempt|null $current Current user's attempt.
     * @return string Escaped HTML.
     */
    public static function render(\stdClass $instance, \stdClass $cm, sequence $sequence, ?attempt $current): string {
        global $OUTPUT;
        $out = '';
        if ($current === null) {
            $blurb = $sequence->is_multi()
                ? get_string('introblurbmulti', 'mod_masteryagent', (object) [
                    'lessons' => $sequence->count(),
                    'turns' => (int) $instance->maxturns,
                ])
                : get_string('introblurb', 'mod_masteryagent', (int) $instance->maxturns);

            $out .= $OUTPUT->box(
                html_writer::tag('p', $blurb)
                . html_writer::div(
                    self::action_form($cm, $current, 'start', 'begin')
                ),
                'generalbox'
            );
            return $out;
        }

        // Progress through the sequence.
        if (!$current->is_finished() && $sequence->is_multi()) {
            $lesson = $sequence->get($current->lesson_index());
            $out .= $OUTPUT->box(
                html_writer::tag('strong', get_string('progress', 'mod_masteryagent', (object) [
                    'position' => $current->lesson_index() + 1,
                    'total' => $sequence->count(),
                ]))
                . ' ' . s($lesson === null ? '' : trim($lesson->lesson_id() . ' ' . $lesson->title())),
                'generalbox py-2'
            );
        }

        // The conversation.
        $out .= html_writer::start_div('masteryagent-conversation');
        foreach ($current->messages() as $message) {
            $isagent = $message->role === 'agent';
            $label = $isagent
                ? get_string('roleagent', 'mod_masteryagent')
                : get_string('rolestudent', 'mod_masteryagent');
            $out .= html_writer::div(
                html_writer::tag('div', $label, ['class' => 'masteryagent-role'])
                . html_writer::tag('div', nl2br(s($message->message)), ['class' => 'masteryagent-text']),
                'masteryagent-message ' . ($isagent ? 'masteryagent-agent' : 'masteryagent-student'),
                ['data-message-id' => $message->id]
            );
        }
        $out .= html_writer::end_div();

        if (!$current->is_finished()) {
            $out .= html_writer::tag(
                'p',
                get_string('turnsleft', 'mod_masteryagent', $current->turns_left()),
                ['class' => 'text-muted']
            );

            $out .= html_writer::start_tag('form', [
                'method' => 'post',
                'action' => new moodle_url('/mod/masteryagent/view.php'),
                'class' => 'masteryagent-form',
                'data-action' => 'reply',
            ]);
            $out .= self::form_fields($cm, $current, 'reply');
            $out .= html_writer::tag('label', get_string('yourreply', 'mod_masteryagent'), ['for' => 'masteryagent-reply']);
            $out .= html_writer::tag('textarea', '', [
                'name' => 'reply',
                'id' => 'masteryagent-reply',
                'maxlength' => attempt::MAX_REPLY_CHARS,
                'rows' => 8,
                'class' => 'form-control',
                'required' => 'required',
                'placeholder' => get_string('replyplaceholder', 'mod_masteryagent'),
            ]);
            $out .= html_writer::div(
                html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'class' => 'btn btn-primary mt-2',
                    'value' => get_string('sendreply', 'mod_masteryagent'),
                ]),
                'mt-2'
            );
            $out .= html_writer::end_tag('form');

            $out .= html_writer::div(
                self::action_form($cm, $current, 'finish', 'finishnow', 'btn btn-link'),
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
            $out .= html_writer::div($body, 'generalbox', ['data-region' => 'results', 'tabindex' => '-1']);

            foreach ($results as $result) {
                $out .= $OUTPUT->box(self::render_lesson_result($result), 'generalbox');
            }

            if (!empty($instance->allowretry)) {
                $out .= html_writer::div(
                    self::action_form($cm, $current, 'start', 'tryagain'),
                    'mt-2'
                );
            }
        }

        return $out;
    }

    /**
     * Render a start, retry or finish POST form, also handled by the AJAX module.
     *
     * @param \stdClass $cm Course module.
     * @param attempt|null $current Latest attempt.
     * @param string $action Action name.
     * @param string $label Language string identifier.
     * @param string $class Button classes.
     * @return string
     */
    private static function action_form(\stdClass $cm, ?attempt $current, string $action,
            string $label, string $class = 'btn btn-primary'): string {
        return html_writer::tag('form', self::form_fields($cm, $current, $action)
            . html_writer::tag('button', get_string($label, 'mod_masteryagent'), [
                'type' => 'submit', 'class' => $class,
            ]), [
                'method' => 'post',
                'action' => new moodle_url('/mod/masteryagent/view.php'),
                'data-action' => $action,
            ]);
    }

    /**
     * Fields shared by AJAX and normal POST submissions.
     *
     * @param \stdClass $cm Course module.
     * @param attempt|null $current Latest attempt.
     * @param string $action Action name.
     * @return string
     */
    private static function form_fields(\stdClass $cm, ?attempt $current, string $action): string {
        $out = '';
        foreach (['id' => $cm->id, 'action' => $action, 'sesskey' => sesskey(),
                'state' => conversation::state($current)] as $name => $value) {
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        return $out;
    }

    /**
     * Render the public assessment for a lesson.
     *
     * @param array $result Stored per-lesson result.
     * @return string
     */
    private static function render_lesson_result(array $result): string {
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

}
