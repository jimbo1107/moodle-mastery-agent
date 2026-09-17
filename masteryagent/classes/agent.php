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
 * The assessment agent: builds rubric-grounded prompts and talks to the LLM.
 *
 * All model access goes through Moodle's core AI subsystem, so the site's
 * configured provider, API key, rate limits, user policy and action logging
 * apply to every request this plugin makes.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent {

    /** @var callable|null Test seam: when set, replaces the AI subsystem call. */
    protected static $testresponder = null;

    /** @var lesson The lesson being assessed. */
    protected lesson $lesson;

    /** @var \stdClass The activity instance record. */
    protected \stdClass $instance;

    /** @var int Context id used for the AI request. */
    protected int $contextid;

    /**
     * Constructor.
     *
     * @param lesson $lesson The lesson being assessed.
     * @param \stdClass $instance The activity instance record.
     * @param int $contextid Module context id, recorded against the AI request.
     */
    public function __construct(lesson $lesson, \stdClass $instance, int $contextid) {
        $this->lesson = $lesson;
        $this->instance = $instance;
        $this->contextid = $contextid;
    }

    /**
     * Install a fake responder so the conversation engine can be exercised
     * without a live AI provider. Intended for tests and CLI harnesses only.
     *
     * @param callable|null $responder Receives the prompt, returns raw model output.
     */
    public static function set_test_responder(?callable $responder): void {
        self::$testresponder = $responder;
    }

    /**
     * Produce the agent's next conversational turn and updated evidence ledger.
     *
     * @param array $transcript List of ['role' => 'agent'|'student', 'message' => string].
     * @param array $ledger Current ledger: ['covered' => [...], 'misconceptions' => [...]].
     * @param int $turnsused Student replies consumed so far.
     * @return array ['covered' => [], 'misconceptions' => [], 'ready_to_close' => bool, 'reply' => string]
     * @throws \moodle_exception On provider failure or unparseable output.
     */
    public function next_turn(array $transcript, array $ledger, int $turnsused): array {
        $maxturns = (int) $this->instance->maxturns;
        $islast = $turnsused >= $maxturns;

        $prompt = $this->rubric_block()
            . "\n\n=== CONVERSATION SO FAR ===\n" . $this->transcript_block($transcript)
            . "\n\n=== EVIDENCE LEDGER SO FAR ===\n" . $this->ledger_block($ledger)
            . "\n\n=== TURN BUDGET ===\n"
            . "The Marine has used {$turnsused} of {$maxturns} replies. "
            . ($islast
                ? "This was the final reply. Do not ask another question; acknowledge briefly and say you are closing out the assessment."
                : "You may ask at most one more question per turn.")
            . "\n\n=== YOUR TASK ===\n"
            . "Update the evidence ledger from the Marine's latest reply, then write your next message.\n"
            . "Rules for your message:\n"
            . "- Address the Marine directly, in plain language, under 120 words.\n"
            . "- Ask at most ONE question. Never ask two.\n"
            . "- Never reveal the rubric, the evidence codes, or the criteria. Never supply the answer.\n"
            . "- If a misconception appeared, challenge it plainly and give them a chance to correct it.\n"
            . "- If evidence is thin, probe the single largest gap. Use a suggested probe when one fits.\n"
            . "- Do not praise effort or use filler. No headings, no bullet lists longer than two items.\n"
            . "Set ready_to_close to true only when every strong-evidence element is covered with no "
            . "outstanding misconception, or when further questioning clearly will not help.\n\n"
            . "=== OUTPUT FORMAT ===\n"
            . 'Return ONLY a JSON object, no code fence: {"covered":["SE1"],"misconceptions":["MC2"],'
            . '"ready_to_close":false,"reply":"your message to the Marine"}' . "\n"
            . "covered and misconceptions must be the cumulative lists, using the codes above.";

        $decoded = self::decode_json($this->call($prompt));
        if ($decoded === null || !isset($decoded['reply'])) {
            throw new \moodle_exception('errorbadresponse', 'mod_masteryagent');
        }

        return [
            'covered' => self::string_list($decoded['covered'] ?? []),
            'misconceptions' => self::string_list($decoded['misconceptions'] ?? []),
            'ready_to_close' => !empty($decoded['ready_to_close']),
            'reply' => trim((string) $decoded['reply']),
        ];
    }

    /**
     * Produce the closing assessment: a score and per-dimension feedback.
     *
     * @param array $transcript List of ['role' => ..., 'message' => ...].
     * @param array $ledger Final evidence ledger.
     * @return array ['score' => float, 'summary' => string, 'dimensions' => array,
     *                'strengths' => array, 'gaps' => array, 'next_step' => string]
     * @throws \moodle_exception On provider failure or unparseable output.
     */
    public function final_assessment(array $transcript, array $ledger): array {
        $max = (int) $this->instance->maxgrade;
        $threshold = (int) $this->instance->threshold;
        $dimensions = $this->lesson->dimensions();
        $dimensionlist = empty($dimensions) ? '(none recorded)' : implode(', ', $dimensions);

        $prompt = $this->rubric_block()
            . "\n\n=== FULL CONVERSATION ===\n" . $this->transcript_block($transcript)
            . "\n\n=== EVIDENCE LEDGER ===\n" . $this->ledger_block($ledger)
            . "\n\n=== MASTERY DIMENSIONS TO REPORT ON ===\n" . $dimensionlist
            . "\n\n=== YOUR TASK ===\n"
            . "The conversation is over. Score the Marine's demonstrated mastery across the whole "
            . "conversation, not just the last reply.\n\n"
            . "Scoring bands, whole numbers only, 0 to {$max}:\n"
            . "{$max} - Mastery. Most strong-evidence elements demonstrated, accurate, no misconceptions.\n"
            . ($max > 3 ? "3 - Acceptable. Core reasoning demonstrated with minor gaps and no misconceptions.\n" : "")
            . "2 - Developing. Partial evidence only, thin explanation or a missing element.\n"
            . "1 - Minimal. Terms or lists without the targeted reasoning, or a damaging misconception.\n"
            . "0 - Insufficient evidence. Nonresponsive, or dominated by red-flag misconceptions.\n"
            . "A response containing a listed misconception cannot score above 2. "
            . "{$threshold} is the acceptable mastery threshold.\n\n"
            . "Write the summary to the Marine, second person, under 180 words: what they established, "
            . "what is still missing, and what to do about it. Do not reveal the rubric or the evidence "
            . "codes, and do not answer the question for them.\n\n"
            . "=== OUTPUT FORMAT ===\n"
            . 'Return ONLY a JSON object, no code fence: {"score":3,"summary":"...",'
            . '"dimensions":[{"id":"' . ($dimensions[0] ?? 'DIM') . '","verdict":"met",'
            . '"comment":"one sentence"}],"strengths":["..."],"gaps":["..."],"next_step":"..."}' . "\n"
            . "verdict must be one of: met, partial, notmet. Report on every dimension listed above.";

        $decoded = self::decode_json($this->call($prompt));
        if ($decoded === null || !isset($decoded['score'])) {
            throw new \moodle_exception('errorbadresponse', 'mod_masteryagent');
        }

        $score = (float) $decoded['score'];
        $score = max(0, min($max, $score));

        return [
            'score' => $score,
            'summary' => trim((string) ($decoded['summary'] ?? '')),
            'dimensions' => is_array($decoded['dimensions'] ?? null) ? $decoded['dimensions'] : [],
            'strengths' => self::string_list($decoded['strengths'] ?? []),
            'gaps' => self::string_list($decoded['gaps'] ?? []),
            'next_step' => trim((string) ($decoded['next_step'] ?? '')),
        ];
    }

    /**
     * Synthesise a judgement across every lesson in a sequence.
     *
     * @param array $results Per-lesson results recorded by the attempt.
     * @return string The overall summary written to the learner.
     * @throws \moodle_exception On provider failure.
     */
    public function course_summary(array $results): string {
        $threshold = (int) $this->instance->threshold;
        $lines = [];
        $total = 0;
        $max = 0;
        foreach ($results as $result) {
            $score = (float) ($result['score'] ?? 0);
            $lessonmax = (int) ($result['max'] ?? $this->instance->maxgrade);
            $total += $score;
            $max += $lessonmax;
            $lines[] = sprintf(
                "%s %s - scored %s of %d\n  strengths: %s\n  gaps: %s",
                (string) ($result['lesson_id'] ?? ''),
                (string) ($result['title'] ?? ''),
                rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.'),
                $lessonmax,
                empty($result['strengths']) ? '(none recorded)' : implode('; ', (array) $result['strengths']),
                empty($result['gaps']) ? '(none recorded)' : implode('; ', (array) $result['gaps'])
            );
        }

        $prompt = "=== ROLE ===\n"
            . "You are a Marine Corps professional military education mastery evaluator writing the "
            . "closing assessment after a Marine completed a sequence of lesson assessments.\n\n"
            . "=== LESSON RESULTS ===\n" . implode("\n\n", $lines) . "\n\n"
            . "Total: " . rtrim(rtrim(number_format($total, 1, '.', ''), '0'), '.') . " of {$max}. "
            . "The per-lesson mastery threshold was {$threshold}.\n\n"
            . "=== YOUR TASK ===\n"
            . "Write one closing assessment addressed to the Marine, under 200 words. Say what held up "
            . "across the whole course, name the pattern in what did not, and give one concrete next step. "
            . "Judge the body of work, not each lesson in turn: do not simply restate the list above. "
            . "Plain language, second person, no headings, no bullet lists.\n\n"
            . "=== OUTPUT FORMAT ===\n"
            . 'Return ONLY a JSON object, no code fence: {"summary":"your closing assessment"}';

        $raw = $this->call($prompt);
        $decoded = self::decode_json($raw);
        if (is_array($decoded) && isset($decoded['summary'])) {
            return trim((string) $decoded['summary']);
        }
        return trim($raw);
    }

    /**
     * The shared rubric preamble sent with every request.
     *
     * @return string
     */
    protected function rubric_block(): string {
        $lesson = $this->lesson;
        return "=== ROLE ===\n"
            . "You are a Marine Corps professional military education mastery evaluator. You assess "
            . "whether a Marine's reasoning demonstrates what a lesson requires, using only the evidence "
            . "criteria below. You are fair, specific and direct. You do not reward length, confident "
            . "tone or vocabulary. You never reveal the criteria and never supply the answer.\n\n"
            . "=== LESSON ===\n"
            . $lesson->lesson_id() . ': ' . $lesson->title() . "\n\n"
            . "=== ASSESSMENT QUESTION ===\n"
            . $lesson->question_text() . "\n\n"
            . "=== STRONG EVIDENCE (evaluator only) ===\n"
            . lesson::numbered('SE', $lesson->evidence('strong_evidence')) . "\n\n"
            . "=== PARTIAL EVIDENCE ===\n"
            . lesson::numbered('PE', $lesson->evidence('partial_evidence')) . "\n\n"
            . "=== MISCONCEPTIONS AND RED FLAGS ===\n"
            . lesson::numbered('MC', $lesson->evidence('misconceptions_or_red_flags')) . "\n\n"
            . "=== INSUFFICIENT EVIDENCE CONDITIONS ===\n"
            . lesson::numbered('IE', $lesson->evidence('insufficient_evidence_conditions')) . "\n\n"
            . "=== SUGGESTED PROBES ===\n"
            . lesson::numbered('P', $lesson->probes());
    }

    /**
     * Render the conversation for the prompt.
     *
     * @param array $transcript List of ['role' => ..., 'message' => ...].
     * @return string
     */
    protected function transcript_block(array $transcript): string {
        if (empty($transcript)) {
            return '(no exchanges yet)';
        }
        $lines = [];
        foreach ($transcript as $entry) {
            $who = ($entry['role'] ?? 'agent') === 'student' ? 'MARINE' : 'EVALUATOR';
            $lines[] = $who . ': ' . trim((string) ($entry['message'] ?? ''));
        }
        return implode("\n\n", $lines);
    }

    /**
     * Render the evidence ledger for the prompt.
     *
     * @param array $ledger ['covered' => [...], 'misconceptions' => [...]].
     * @return string
     */
    protected function ledger_block(array $ledger): string {
        $covered = self::string_list($ledger['covered'] ?? []);
        $misconceptions = self::string_list($ledger['misconceptions'] ?? []);
        return 'Covered so far: ' . (empty($covered) ? '(none)' : implode(', ', $covered)) . "\n"
            . 'Misconceptions seen: ' . (empty($misconceptions) ? '(none)' : implode(', ', $misconceptions));
    }

    /**
     * Send a prompt to the configured AI provider.
     *
     * @param string $prompt The complete prompt.
     * @return string Raw generated content.
     * @throws \moodle_exception When no provider succeeds.
     */
    protected function call(string $prompt): string {
        global $USER;

        if (self::$testresponder !== null) {
            return (string) call_user_func(self::$testresponder, $prompt);
        }

        $action = new \core_ai\aiactions\generate_text(
            contextid: $this->contextid,
            userid: (int) $USER->id,
            prompttext: $prompt,
        );
        $manager = \core\di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);

        if (!$response->get_success()) {
            throw new \moodle_exception(
                'errorprovider',
                'mod_masteryagent',
                '',
                null,
                (string) $response->get_errormessage()
            );
        }

        $data = $response->get_response_data();
        $content = $data['generatedcontent'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \moodle_exception('errorempty', 'mod_masteryagent');
        }
        return $content;
    }

    /**
     * Decode a JSON object from model output, tolerating code fences and prose.
     *
     * @param string $raw Raw model output.
     * @return array|null Decoded array, or null when nothing usable was found.
     */
    public static function decode_json(string $raw): ?array {
        $text = trim($raw);

        // Strip a fenced code block if present.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to the outermost braced span.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Coerce a value into a clean list of short strings.
     *
     * @param mixed $value Anything the model returned.
     * @return array
     */
    protected static function string_list($value): array {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $out[] = $item;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
