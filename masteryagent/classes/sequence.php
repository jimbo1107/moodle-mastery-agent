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

namespace mod_masteryagent;

/**
 * The ordered set of lessons one activity assesses.
 *
 * A single-lesson activity is just a sequence of one, so the conversation
 * engine has only one shape to handle.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sequence {

    /** @var lesson[] Ordered lessons. */
    protected array $lessons = [];

    /**
     * Constructor.
     *
     * @param array $records Ordered question records.
     */
    public function __construct(array $records) {
        foreach ($records as $record) {
            if (is_array($record) && !empty($record['question_text'])) {
                $this->lessons[] = new lesson($record);
            }
        }
    }

    /**
     * Build from an activity instance.
     *
     * Falls back to the single-lesson field for activities created before
     * sequence mode existed.
     *
     * @param \stdClass $instance Activity instance record.
     * @return self
     */
    public static function from_instance(\stdClass $instance): self {
        $decoded = json_decode((string) ($instance->sequencejson ?? ''), true);
        if (is_array($decoded) && !empty($decoded)) {
            return new self($decoded);
        }

        $single = json_decode((string) ($instance->lessonjson ?? ''), true);
        if (is_array($single) && !empty($single)) {
            return new self([$single]);
        }

        return new self([]);
    }

    /**
     * Select records from a parsed question set, in the requested order.
     *
     * An empty key list means every question in the file, in file order.
     *
     * @param array $questions Parsed question set, keyed by question id.
     * @param string $keys Comma separated lesson or question ids.
     * @return array Ordered question records.
     * @throws \moodle_exception When a requested key is not in the file.
     */
    public static function select(array $questions, string $keys): array {
        $keys = trim($keys);
        if ($keys === '') {
            return array_values($questions);
        }

        $selected = [];
        foreach (preg_split('/[\s,]+/', $keys, -1, PREG_SPLIT_NO_EMPTY) as $key) {
            $match = null;
            if (isset($questions[$key])) {
                $match = $questions[$key];
            } else {
                foreach ($questions as $question) {
                    if (($question['lesson_id'] ?? '') === $key) {
                        $match = $question;
                        break;
                    }
                }
            }
            if ($match === null) {
                throw new \moodle_exception('errorlessonnotfound', 'mod_masteryagent', '', $key);
            }
            $selected[] = $match;
        }
        return $selected;
    }

    /**
     * How many lessons are in the sequence.
     *
     * @return int
     */
    public function count(): int {
        return count($this->lessons);
    }

    /**
     * Whether the sequence holds more than one lesson.
     *
     * @return bool
     */
    public function is_multi(): bool {
        return $this->count() > 1;
    }

    /**
     * Fetch a lesson by position.
     *
     * @param int $index Zero-based position.
     * @return lesson|null
     */
    public function get(int $index): ?lesson {
        return $this->lessons[$index] ?? null;
    }

    /**
     * Every lesson, in order.
     *
     * @return lesson[]
     */
    public function all(): array {
        return $this->lessons;
    }

    /**
     * The identifier used to tag messages for a position in the sequence.
     *
     * @param int $index Zero-based position.
     * @return string
     */
    public function key_for(int $index): string {
        $lesson = $this->get($index);
        if ($lesson === null) {
            return '';
        }
        $key = $lesson->question_id();
        return $key !== '' ? $key : ($lesson->lesson_id() !== '' ? $lesson->lesson_id() : (string) $index);
    }

    /**
     * A short human description of the whole sequence, e.g. "L01 - L13 (13 lessons)".
     *
     * @return string
     */
    public function describe(): string {
        if ($this->count() === 0) {
            return '';
        }
        $first = $this->get(0);
        if ($this->count() === 1) {
            return trim($first->lesson_id() . ' ' . $first->title());
        }
        $last = $this->get($this->count() - 1);
        return $first->lesson_id() . ' - ' . $last->lesson_id()
            . ' (' . $this->count() . ')';
    }
}
