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
 * Instance settings form for mod_masteryagent.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Instance settings form.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_masteryagent_mod_form extends moodleform_mod {

    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('activityname', 'mod_masteryagent'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'sourceheader', get_string('sourceheader', 'mod_masteryagent'));
        $mform->setExpanded('sourceheader');

        $mform->addElement(
            'filepicker',
            'lessonfile',
            get_string('lessonfile', 'mod_masteryagent'),
            null,
            ['accepted_types' => ['.json'], 'maxfiles' => 1]
        );
        $mform->addHelpButton('lessonfile', 'lessonfile', 'mod_masteryagent');

        $mform->addElement('text', 'lessonkeys', get_string('lessonkeys', 'mod_masteryagent'), ['size' => '48']);
        $mform->setType('lessonkeys', PARAM_TEXT);
        $mform->addHelpButton('lessonkeys', 'lessonkeys', 'mod_masteryagent');

        if (!empty($this->_instance)) {
            $mform->addElement(
                'static',
                'currentlesson',
                get_string('currentlesson', 'mod_masteryagent'),
                $this->current_lesson_summary()
            );
        }

        $mform->addElement('header', 'assessmentheader', get_string('assessmentheader', 'mod_masteryagent'));
        $mform->setExpanded('assessmentheader');

        $turnoptions = [];
        for ($i = 2; $i <= 12; $i++) {
            $turnoptions[$i] = $i;
        }
        $mform->addElement('select', 'maxturns', get_string('maxturns', 'mod_masteryagent'), $turnoptions);
        $mform->setDefault('maxturns', 6);
        $mform->addHelpButton('maxturns', 'maxturns', 'mod_masteryagent');

        $mform->addElement('text', 'maxgrade', get_string('maxgrade', 'mod_masteryagent'), ['size' => '4']);
        $mform->setType('maxgrade', PARAM_INT);
        $mform->setDefault('maxgrade', 4);

        $mform->addElement('text', 'threshold', get_string('threshold', 'mod_masteryagent'), ['size' => '4']);
        $mform->setType('threshold', PARAM_INT);
        $mform->setDefault('threshold', 3);
        $mform->addHelpButton('threshold', 'threshold', 'mod_masteryagent');

        $mform->addElement('advcheckbox', 'allowretry', get_string('allowretry', 'mod_masteryagent'));
        $mform->setDefault('allowretry', 1);
        $mform->addHelpButton('allowretry', 'allowretry', 'mod_masteryagent');

        $mform->addElement('advcheckbox', 'provisional', get_string('provisional', 'mod_masteryagent'));
        $mform->setDefault('provisional', 1);
        $mform->addHelpButton('provisional', 'provisional', 'mod_masteryagent');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Describe the lesson currently loaded into this instance.
     *
     * @return string
     */
    protected function current_lesson_summary(): string {
        global $DB;

        $instance = $DB->get_record('masteryagent', ['id' => $this->_instance]);
        if (!$instance || empty($instance->lessonjson)) {
            return get_string('nolessonloaded', 'mod_masteryagent');
        }

        $meta = json_decode((string) $instance->sourcemeta, true);
        $status = is_array($meta) ? ($meta['rubric_validation_status'] ?? '') : '';

        $sequence = \mod_masteryagent\sequence::from_instance($instance);
        if ($sequence->is_multi()) {
            $names = [];
            foreach ($sequence->all() as $lesson) {
                $names[] = trim($lesson->lesson_id() . ' ' . $lesson->title());
            }
            $summary = s(get_string('lessoncount', 'mod_masteryagent', $sequence->count()))
                . '<br>' . s(implode(' · ', $names));
        } else {
            $summary = s($instance->lessonid . ' ' . $instance->lessontitle);
            if ($instance->questionid !== '') {
                $summary .= ' (' . s($instance->questionid) . ')';
            }
        }
        if ($status !== '') {
            $summary .= '<br>' . s(get_string('sourcestatus', 'mod_masteryagent', $status));
        }
        return $summary;
    }

    /**
     * Validate the submitted settings.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);

        if ((int) $data['threshold'] > (int) $data['maxgrade']) {
            $errors['threshold'] = get_string('errorthreshold', 'mod_masteryagent');
        }
        if ((int) $data['maxgrade'] < 1) {
            $errors['maxgrade'] = get_string('errormaxgrade', 'mod_masteryagent');
        }

        $contents = null;
        if (!empty($data['lessonfile'])) {
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $uploaded = $fs->get_area_files($usercontext->id, 'user', 'draft', $data['lessonfile'], 'id', false);
            if (!empty($uploaded)) {
                $file = reset($uploaded);
                $contents = $file->get_content();
            }
        }

        if ($contents === null) {
            if (empty($this->_instance)) {
                $errors['lessonfile'] = get_string('errorfilerequired', 'mod_masteryagent');
            }
            return $errors;
        }

        $questions = \mod_masteryagent\lesson::parse_question_set($contents);
        if (empty($questions)) {
            $errors['lessonfile'] = get_string('errorbadjson', 'mod_masteryagent');
            return $errors;
        }

        try {
            \mod_masteryagent\sequence::select($questions, (string) ($data['lessonkeys'] ?? ''));
        } catch (moodle_exception $e) {
            $errors['lessonkeys'] = get_string(
                'errorlessonrequired',
                'mod_masteryagent',
                implode(', ', array_keys($questions))
            );
        }

        return $errors;
    }
}
