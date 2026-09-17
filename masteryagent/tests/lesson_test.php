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

require_once(__DIR__ . '/helper_trait.php');

/**
 * Reading a question set: what the plugin accepts, and what it ignores.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lesson::class)]
final class lesson_test extends \advanced_testcase {

    use helper_trait;

    public function test_parses_the_wrapper_form(): void {
        $questions = $this->fixture_questions();

        $this->assertCount(3, $questions);
        $this->assertArrayHasKey('S01-Q01', $questions);
        $this->assertArrayHasKey('S03-Q01', $questions);
    }

    public function test_parses_a_bare_array(): void {
        $decoded = json_decode($this->fixture_json(), true);
        $bare = json_encode($decoded['questions']);

        $this->assertCount(3, lesson::parse_question_set($bare));
    }

    public function test_skips_records_without_a_question(): void {
        $decoded = json_decode($this->fixture_json(), true);
        $decoded['questions'][] = ['lesson_id' => 'S04', 'question_id' => 'S04-Q01'];

        $this->assertCount(3, lesson::parse_question_set(json_encode($decoded)));
    }

    public function test_rejects_content_that_is_not_a_question_set(): void {
        $this->assertSame([], lesson::parse_question_set('not json at all'));
        $this->assertSame([], lesson::parse_question_set('{"something":"else"}'));
    }

    public function test_reads_source_metadata(): void {
        $meta = lesson::parse_source_meta($this->fixture_json());

        $this->assertSame('Sample Course', $meta['course']);
        $this->assertSame('1.0', $meta['schema_version']);
        $this->assertSame('DRAFT_PENDING_HUMAN_VALIDATION', $meta['rubric_validation_status']);
    }

    public function test_exposes_the_fields_the_agent_needs(): void {
        $questions = $this->fixture_questions();
        $lesson = new lesson($questions['S01-Q01']);

        $this->assertSame('S01', $lesson->lesson_id());
        $this->assertSame('S01-Q01', $lesson->question_id());
        $this->assertSame('Reading the Ground', $lesson->title());
        $this->assertStringContainsString('digital map display', $lesson->question_text());
        $this->assertSame(['S01-MD01', 'S01-MD02'], $lesson->dimensions());
        $this->assertSame(['S01-EO01', 'S01-EO02'], $lesson->objectives());
        $this->assertCount(2, $lesson->probes());
        $this->assertCount(3, $lesson->evidence('strong_evidence'));
        $this->assertCount(2, $lesson->evidence('misconceptions_or_red_flags'));
        $this->assertCount(1, $lesson->sources());
        $this->assertCount(1, $lesson->validation_notes());
    }

    public function test_missing_rubric_sections_return_empty_lists(): void {
        $lesson = new lesson(['question_text' => 'A question with no guide.']);

        $this->assertSame([], $lesson->evidence('strong_evidence'));
        $this->assertSame([], $lesson->probes());
        $this->assertSame([], $lesson->dimensions());
        $this->assertSame('', $lesson->lesson_id());
    }

    public function test_round_trips_through_stored_json(): void {
        $questions = $this->fixture_questions();
        $lesson = lesson::from_json(json_encode($questions['S02-Q01']));

        $this->assertSame('S02', $lesson->lesson_id());
        $this->assertCount(3, $lesson->evidence('strong_evidence'));
    }

    public function test_unreadable_stored_json_raises(): void {
        $this->expectException(\moodle_exception::class);
        lesson::from_json('{ not json');
    }

    public function test_numbered_lists_are_coded_for_the_prompt(): void {
        $this->assertSame("SE1. first\nSE2. second", lesson::numbered('SE', ['first', 'second']));
        $this->assertSame('(none supplied)', lesson::numbered('P', []));
    }
}
