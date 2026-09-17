# Agent 1 — Rubric Agent

Reads the AY27 8670 Prerequisite Course coursebook and produces the **mastery rubric** that
everything downstream is built on: what each lesson requires a Marine to understand, what
counts as evidence of it, and what a wrong answer looks like.

This is the first stage of a three-part pipeline:

```
   this directory                agent2 - question generator      masteryagent (Moodle plugin)
┌──────────────────────┐      ┌──────────────────────────┐      ┌──────────────────────────┐
│ Coursebook PDF       │ ───► │ Rubric + coursebook       │ ───► │ Teacher uploads the JSON │
│ → source inventory   │      │ → one open-ended question │      │ → agent interviews the   │
│ → Educational        │      │   + evidence guide per    │      │   Marine, scores each    │
│   Objectives         │      │   lesson                  │      │   lesson, writes to the  │
│ → mastery dimensions │      │                           │      │   gradebook              │
│ → 1–5 rubric levels  │      │ question_set.json         │      └──────────────────────────┘
└──────────────────────┘      └──────────────────────────┘
  ..._Draft_Mastery_Rubrics.json
         + .xlsx for humans
```

Agent 1 **describes mastery. It does not write questions and does not assess anyone.** Question
wording belongs to agent 2; interviewing and scoring belong to the Moodle plugin.

---

## What's in this directory

| File | Role |
| --- | --- |
| `agent.md` | **The agent's instructions.** This is the prompt you hand to the model. |
| `AY27_8670_Prerequisite_Coursebook___Instructor-Led_Moodle.pdf` | The 220-page coursebook — 13 lessons, Educational Objectives, required readings, embedded instructional text. The content authority. |
| `README.md` | This file. |

---

## Running it

Point a capable agent at this directory with `agent.md` as its instructions.

```
Build the full rubric for all 13 lessons.
```
```
Rebuild the rubric — the coursebook has been updated.
```
```
Redo Lesson 3 only; the NDS baseline question has been resolved.
```

It works in five ordered phases and will not skip ahead: inventory the sources, extract and
normalise the Educational Objectives, consolidate them into mastery dimensions, draft the 1–5
levels, then run a coverage and consistency pass. Expect it to be slow — the coursebook is 220
pages and required readings are retrieved from official sources where they aren't embedded.

### What it produces

1. **`AY27_8670_Draft_Mastery_Rubrics.json`** — the machine deliverable. Agent 2 loads this by
   exact key name, so the filename and structure are a contract, not a preference.
2. **An `.xlsx` workbook** — the same content laid out for human review: source inventory,
   objectives, dimensions, rubric levels, coverage matrix, and issues for SME review.

Both must carry the same IDs and the same substance. The spreadsheet is what an SME marks up;
the JSON is what the pipeline runs on.

---

## Checking the output

```bash
python3 -c "import json;d=json.load(open('AY27_8670_Draft_Mastery_Rubrics.json'));\
print(d['validation_status'], d['generation_timestamp']);\
print(json.dumps(d['qa'], indent=1));\
[print(l['lesson_id'], l['validation_status'], '|', l['evidence_status']) for l in d['lessons']]"
```

Four things are worth eyeballing every run:

- **All 13 lessons present**, or explicitly listed as blocked.
- **Five rubric levels per dimension** — `qa.rubric_level_count` should equal
  `qa.dimension_count × 5`.
- **Every objective mapped** to at least one dimension (`qa.objectives_mapped_to_dimensions`
  should equal `qa.objective_count`).
- **Every lesson carries both** `validation_status` and `evidence_status`. Agent 2 gates on
  these; a lesson missing them is a lesson it cannot safely use.

The longer self-check command is in `agent.md` under **Downstream contract**.

---

## What agent 2 needs from you

Most of `agent.md` is about getting the rubric *right*. This section is about getting it
*usable*, and it's where a regenerated rubric most often breaks the pipeline.

**The filename and the key names are a contract.** Agent 2 opens
`AY27_8670_Draft_Mastery_Rubrics.json` literally and reads `lessons`, `mastery_dimensions`,
`educational_objectives`, `rubric_levels`, `sources`, `source_gaps`, `issues_for_sme_review` and
`scoring_policy.insufficient_evidence_rule` by name. Rename one and agent 2 finds nothing where
it expected something — with no error, just a thinner question set. The full field list is in
`agent.md`.

**`essential_concepts_evidence` and `critical_misconceptions_omissions` do the most work.** They
become the checklist the live evaluator scores a Marine against. That evaluator marks each item
demonstrated or not demonstrated, with nothing in between — so a compound item like "Explains X
and compares Y to Z" is unscoreable, because it can't report which half was missing. One claim
per item, phrased as what the Marine would have to say rather than the topic they'd be
discussing.

**Lesson status fields are a gate, not a label.** Anything other than `ready_for_review` tells
agent 2 to build around the unresolved part rather than assess it. Put the specific open
question in `high_priority_notes` so it knows *what* to avoid instead of dropping the lesson.

**Keep IDs stable across runs.** A dimension that keeps its meaning must keep its ID. Agent 2
derives its question IDs from lesson IDs and keeps them stable so that re-uploading a question
set into an existing Moodle activity updates it in place. A renamed ID silently retargets an
activity at a different lesson.

**Dimensions must be answerable in two to three minutes.** Agent 2 writes one short open-ended
question per lesson, and the live evaluator has a limited number of replies to work with. A
dimension too large to evidence in a short spoken answer will be reported unmet no matter how
well the Marine understands it. Flag those in `issues_for_sme_review` rather than shipping them.

**Your 1–5 scale stays here.** It never reaches the learner. The Moodle activity sets its own
maximum score per lesson at configuration time and builds its bands from that value, so write
level descriptors that stay true whatever the eventual scale is — describe demonstrated
understanding, never point values.

---

## Before anyone relies on this

The rubric is **decision support pending human validation**, and says so in its own
`validation_status`. Lesson titles, objective wording, required/optional distinctions and source
metadata come from the coursebook and are confirmed. The dimension groupings, criticality
defaults, misconception lists, level descriptors and aggregation rules are the agent's
judgments. The `confirmed_vs_generated` block in the JSON draws that line explicitly — keep it
accurate, because it is what tells a reviewer which parts to scrutinise.

Read `issues_for_sme_review` and `source_gaps` before treating any of it as settled. Typical
entries are a superseded edition still named by the coursebook, a citation conflict between two
dated versions of the same publication, a reading that is time-sensitive, or content missing
from the captured PDF. These need a curriculum owner's decision, not another agent run.

Nothing here should be described as operationally validated, and no rubric produced by this
agent should be used as final grading policy until a subject matter expert has signed it off.
