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
 * Choosing which lessons an activity assesses, and in what order.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sequence::class)]
final class sequence_test extends \advanced_testcase {

    use helper_trait;

    public function test_blank_selection_takes_every_lesson_in_file_order(): void {
        $selected = sequence::select($this->fixture_questions(), '');

        $this->assertCount(3, $selected);
        $this->assertSame(['S01', 'S02', 'S03'], array_column($selected, 'lesson_id'));
    }

    public function test_selection_follows_the_order_given(): void {
        $selected = sequence::select($this->fixture_questions(), 'S03, S01');

        $this->assertSame(['S03', 'S01'], array_column($selected, 'lesson_id'));
    }

    public function test_selection_accepts_lesson_or_question_ids(): void {
        $questions = $this->fixture_questions();

        $this->assertSame(['S02'], array_column(sequence::select($questions, 'S02'), 'lesson_id'));
        $this->assertSame(['S02'], array_column(sequence::select($questions, 'S02-Q01'), 'lesson_id'));
    }

    public function test_selection_tolerates_untidy_separators(): void {
        $selected = sequence::select($this->fixture_questions(), " S01 ,S02 \n S03 ");

        $this->assertCount(3, $selected);
    }

    public function test_unknown_lesson_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        sequence::select($this->fixture_questions(), 'S01,S99');
    }

    public function test_counts_and_describes_itself(): void {
        $questions = $this->fixture_questions();

        $single = new sequence(sequence::select($questions, 'S01'));
        $this->assertSame(1, $single->count());
        $this->assertFalse($single->is_multi());
        $this->assertSame('S01 Reading the Ground', $single->describe());

        $all = new sequence(sequence::select($questions, ''));
        $this->assertSame(3, $all->count());
        $this->assertTrue($all->is_multi());
        $this->assertSame('S01 - S03 (3)', $all->describe());
    }

    public function test_positions_and_keys(): void {
        $sequence = new sequence(sequence::select($this->fixture_questions(), ''));

        $this->assertSame('S01-Q01', $sequence->key_for(0));
        $this->assertSame('S03-Q01', $sequence->key_for(2));
        $this->assertSame('', $sequence->key_for(9));
        $this->assertSame('S02', $sequence->get(1)->lesson_id());
        $this->assertNull($sequence->get(9));
        $this->assertCount(3, $sequence->all());
    }

    public function test_falls_back_to_lesson_id_when_no_question_id(): void {
        $sequence = new sequence([[
            'lesson_id' => 'S07',
            'question_text' => 'A lesson identified only by its lesson id.',
        ]]);

        $this->assertSame('S07', $sequence->key_for(0));
    }

    public function test_reads_lessons_from_an_activity_instance(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $course->id,
            'lessonkeys' => 'S01,S02',
        ]);

        $sequence = sequence::from_instance($instance);

        $this->assertSame(2, $sequence->count());
        $this->assertSame(['S01', 'S02'], array_map(fn($l) => $l->lesson_id(), $sequence->all()));
    }

    public function test_reads_a_single_lesson_instance_saved_before_sequences_existed(): void {
        $questions = $this->fixture_questions();
        $legacy = (object) [
            'sequencejson' => null,
            'lessonjson' => json_encode($questions['S01-Q01']),
        ];

        $sequence = sequence::from_instance($legacy);

        $this->assertSame(1, $sequence->count());
        $this->assertSame('S01', $sequence->get(0)->lesson_id());
    }

    public function test_an_instance_with_no_lessons_is_empty_not_fatal(): void {
        $sequence = sequence::from_instance((object) ['sequencejson' => null, 'lessonjson' => null]);

        $this->assertSame(0, $sequence->count());
        $this->assertNull($sequence->get(0));
        $this->assertSame('', $sequence->describe());
    }
}
