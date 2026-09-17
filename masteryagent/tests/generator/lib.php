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
 * Test data generator for mod_masteryagent.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Creates mastery agent activities for tests.
 *
 * Loads the bundled sample question set unless the caller supplies its own
 * lessons, so a test only has to say which lessons it wants:
 *
 *     $this->getDataGenerator()->create_module('masteryagent', [
 *         'course' => $course->id,
 *         'lessonkeys' => 'S01,S02',
 *     ]);
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_masteryagent_generator extends testing_module_generator {

    /**
     * Path to the question set bundled with the tests.
     *
     * @return string
     */
    public static function fixture_path(): string {
        return __DIR__ . '/../fixtures/sample_question_set.json';
    }

    /**
     * The bundled question set as raw JSON.
     *
     * @return string
     */
    public static function fixture_json(): string {
        return file_get_contents(self::fixture_path());
    }

    /**
     * Create an activity instance.
     *
     * @param array|stdClass|null $record Instance settings. 'lessonkeys' selects
     *                                    lessons from the bundled fixture.
     * @param array|null $options Generator options.
     * @return stdClass The activity instance record.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'name' => 'Mastery agent',
            'intro' => 'Assessment conversation.',
            'introformat' => FORMAT_HTML,
            'maxturns' => 6,
            'maxgrade' => 4,
            'threshold' => 3,
            'allowretry' => 1,
            'provisional' => 1,
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }

        // Unless the caller supplied lessons, take them from the fixture.
        if (!isset($record->sequencejson)) {
            $json = self::fixture_json();
            $questions = \mod_masteryagent\lesson::parse_question_set($json);
            $selected = \mod_masteryagent\sequence::select($questions, (string) ($record->lessonkeys ?? ''));
            $first = reset($selected);

            $record->sequencejson = json_encode(array_values($selected));
            $record->lessonjson = json_encode($first);
            $record->lessonid = (string) ($first['lesson_id'] ?? '');
            $record->questionid = (string) ($first['question_id'] ?? '');
            $record->lessontitle = (string) ($first['lesson_title'] ?? '');
            $record->sourcemeta = json_encode(\mod_masteryagent\lesson::parse_source_meta($json));
        }
        if (!isset($record->lessonkeys)) {
            $record->lessonkeys = '';
        }

        return parent::create_instance($record, (array) $options);
    }
}
