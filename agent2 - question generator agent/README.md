# Agent 2 — Question Generator

Turns the AY27 8670 Prerequisite Course rubric into a **question-set JSON file that loads
straight into the Moodle `mod_masteryagent` plugin**.

This is the middle stage of a three-part pipeline:

```
agent1 - rubric agent          this directory                  masteryagent (Moodle plugin)
┌──────────────────────┐      ┌──────────────────────┐        ┌──────────────────────────┐
│ Coursebook PDF       │ ───► │ Rubric + coursebook  │  ───►  │ Teacher uploads the JSON │
│ → Educational        │      │ → one open-ended     │        │ → agent interviews the   │
│   Objectives         │      │   question + evidence│        │   Marine, scores each    │
│ → 1–5 mastery rubric │      │   guide per lesson   │        │   lesson, writes to the  │
└──────────────────────┘      └──────────────────────┘        │   gradebook              │
   AY27_..._Rubrics.json        question_set.json             └──────────────────────────┘
```

Agent 2 **writes assessment material. It never assesses a student.** The interviewing and
scoring happen inside the Moodle plugin, driven by the evidence guide this agent produces.

---

## What's in this directory

| File | Role |
| --- | --- |
| `agent.md` | **The agent's instructions.** This is the prompt you hand to the model. |
| `AY27_8670_Prerequisite_Coursebook___Instructor-Led_Moodle.pdf` | The 220-page coursebook — 13 lessons, objectives, embedded readings. The content authority. |
| `AY27_8670_Draft_Mastery_Rubrics.json` | Agent 1's output: Educational Objectives, mastery dimensions, 1–5 level descriptors, source inventory, open SME issues. **Regenerated upstream — treat its contents as current, not fixed.** |
| `masteryagent_question_set_spec.md` | The plugin's contract — exact field names and the writing rules that make a rubric grade well. **Authoritative; beats `agent.md` on any conflict.** |
| `masteryagent_question_set_template.json` | One complete, validator-clean lesson record to copy the shape from. |
| `validate_question_set.py` | The shipping gate. Catches anything that would break or degrade the plugin. |
| `README.md` | This file. |

---

## Running it

Point a capable agent at this directory with `agent.md` as its instructions, then ask for
what you want. The agent reads the coursebook and rubric itself.

```
Generate question sets for lessons 1 through 4.
```
```
Generate the full 13-lesson question set and validate it.
```
```
Regenerate L07 only — keep the existing question_id.
```

It will write a file (default `masteryagent_question_set_AY27_8670.json`), run the validator
on it, and fix what comes back. A full 13-lesson run is long; lesson-at-a-time or in small
batches is easier to review.

### Check the output yourself

```bash
python3 validate_question_set.py masteryagent_question_set_AY27_8670.json
```

Exit code 0 means it will load. Errors mean it breaks or silently loses a lesson. Warnings
mean it loads but assesses poorly — a missing probe list, a one-item evidence list, a question
that gives away its own answer. Ask the agent to justify any warning it left standing.

Sanity-check the validator itself against the shipped template, which is clean:

```bash
python3 validate_question_set.py masteryagent_question_set_template.json
# → OK — no errors, no warnings
```

### Load it into Moodle

Upload the JSON on a `mod_masteryagent` activity and list which lesson IDs that activity
assesses (blank = all of them). Re-uploading a revised file onto an existing activity replaces
the questions in place — which is why **`question_id` values must stay stable across
regenerations**. Changing an ID repoints the activity at a different lesson.

---

## What one lesson record contains

One record per lesson, each independently usable:

- **`question_text`** — a short scenario plus one instruction (~40–120 words), shown to the
  Marine verbatim. Answerable in 2–3 minutes from the assigned reading alone.
- **`evaluator_evidence_guide`** — four lists that drive the whole conversation:
  - `strong_evidence` (3–5 items) — the ledger. The evaluator tracks which the Marine has
    demonstrated, probes the biggest gap, and closes the lesson once they're all covered.
  - `partial_evidence` — right direction, underdeveloped.
  - `misconceptions_or_red_flags` — penalised, and challenged directly.
  - `insufficient_evidence_conditions` — what counts as nonresponsive.
- **`target_mastery_dimensions`** — rubric dimension IDs (`L01-MD01`). One judgement is
  reported per dimension, so they must match the rubric exactly.
- **`follow_up_probes`** — single questions, read to the Marine verbatim when they stall.
- **`source_evidence`, `validation_notes`** — shown to instructors in the report so a judgement
  can be traced back to the reading.

The evidence guide, misconception list and probes are **never shown to a learner**. They go to
the model and to anyone with report permissions. Treat the output file as an answer key.

Only part of the record reaches the evaluator model: the lesson ID and title, the question, the
four evidence lists, the probes, and the dimension IDs. `source_evidence`, `validation_notes`,
`selection_rationale` and the objective IDs are instructor-report material and are never sent.

Two details explain why the evidence lists are written the way they are. The plugin numbers the
items into the prompt (`SE1`, `SE2`, `MC1` …) and the evaluator reports back which codes it has
seen, so an item is either covered or not — **a compound item can never be half-credited**, which
is why each one states a single claim. And the evaluator gets a fixed reply budget per lesson
(instructor-set, 2–12 turns, default 6), which is why `strong_evidence` targets 3–5 items: a
longer list runs the lesson out of turns before it can be covered.

---

## Before you trust the output

**The rubric is a draft, and it is regenerated upstream.** `AY27_8670_Draft_Mastery_Rubrics.json`
is marked `DRAFT_PENDING_HUMAN_VALIDATION`. The lesson titles, objective wording and source
metadata come from the coursebook; the dimension groupings, misconception lists and level
descriptors are agent judgments. Questions built on them inherit that status — keep the plugin's
AI-provisional grade flag on until an SME has signed off.

Because agent 1 normally re-runs before agent 2, **which lessons are flagged changes between
runs.** Don't work from a remembered list; ask the current rubric:

```bash
python3 -c "import json;d=json.load(open('AY27_8670_Draft_Mastery_Rubrics.json'));\
print(d['validation_status'], d['generation_timestamp']);\
[print(l['lesson_id'], l['validation_status'], '|', l['evidence_status']) for l in d['lessons']]"
```

Any lesson not marked `ready_for_review` has an unresolved source problem — typically a
superseded edition, a citation conflict, a time-sensitive reading, or content missing from the
captured PDF. The agent is instructed to build the question around what the lesson *can* fairly
support and to record what it avoided. Read the file's `source_gaps` array and each record's
`validation_notes` before releasing anything, and check the rubric's `issues_for_sme_review` for
what still needs a human decision.

**Scoring scale lives in the activity, not in the question set.** The question set contains no
numbers at all. Each Moodle activity sets its own **Maximum score per lesson** (`maxgrade`,
default 4, minimum 1) and **mastery threshold**, and the plugin builds its scoring bands from
those at run time — so one question set can run at 4 points in one activity and 10 in another.
The evidence guide is therefore written to describe what a Marine demonstrated, never what it is
worth. One thing to watch if you raise `maxgrade` much above the default: the plugin's band text
describes the top score, then 3, 2, 1 and 0, so setting it to 10 leaves scores 4 through 9
without a written definition. Either keep it near the default or check how the bands read at the
value you choose.

**Lesson 13 is sensitive.** Scenarios must stay professional, non-graphic and trauma-informed,
testing prevention, command climate, risk recognition and leadership action. Nothing in the
question set should invite a learner to disclose personal experience.

---

## Editing the agent

`agent.md` is the whole configuration — there is no code to change. If you adjust it, keep
these intact, since they're what makes the output loadable rather than merely plausible:

- the exact field names in the output contract (the plugin silently drops misspelled keys);
- the validator limits (IDs ≤ 64 chars from `A-Za-z0-9._-`, `question_text` 60–1200 chars,
  `strong_evidence` ≥ 2 or it's a hard error);
- the rule against restating the rubric inside `question_text` — the validator compares content
  words and warns at ~70% overlap, because a question containing its own answer measures
  reading comprehension of the prompt;
- the instruction to actually run `validate_question_set.py` before handing anything over.

If `agent.md` and `masteryagent_question_set_spec.md` ever disagree, the spec is right and
`agent.md` needs updating.
