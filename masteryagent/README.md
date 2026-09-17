# Mastery agent (mod_masteryagent)

A Moodle activity that assesses a learner through a bounded conversation
instead of a multiple-choice quiz.

Built for the MCU-NPS AI Learning Initiatives use case *PME Course Material
Mastery Evaluation Agent* (EWSDEP 8670 prerequisite course).

## What it does

1. A teacher uploads the course question-set JSON and names which lessons this
   activity assesses — one, a few, or all of them.
2. The agent puts the first lesson's question to the learner.
3. Each reply is judged against that lesson's evidence rubric. The agent tracks
   which strong-evidence elements have been demonstrated and which
   misconceptions have appeared, then probes the largest remaining gap — one
   question at a time.
4. A lesson closes when its evidence is complete or its reply budget is spent.
   The agent says so and puts the next lesson's question straight away; the
   learner never has to navigate anywhere.
5. After the last lesson the agent reports a score and written feedback for
   every lesson, adds an overall judgement across the whole run, and pushes the
   total to the gradebook.

A learner can stop part way and come back: the attempt keeps its place, and
lessons never reached simply score nothing.

## Requirements

- Moodle 4.5 or later (5.1 recommended; developed and tested against 5.1.7).
- An AI provider configured in **Site administration → General → AI → AI
  providers**, with the **Generate text** action enabled.

All model access goes through `\core_ai\manager`, so the site's API key, rate
limits, AI user policy and `ai_action_register` logging apply to every request
this plugin makes. The plugin stores no credentials of its own.

## Installation

Upload the zip at **Site administration → Plugins → Install plugins**, or
extract into `mod/masteryagent` (`public/mod/masteryagent` on Moodle 5.0+).

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Question set file | — | The course JSON. May contain every lesson. |
| Lessons to assess | — | Comma separated lesson or question IDs, in the order to run them. Blank means every lesson in the file. |
| Maximum replies per lesson | 6 | Hard ceiling per lesson. The agent may close one sooner. |
| Maximum score per lesson | 4 | Gradebook maximum is this times the number of lessons. |
| Mastery threshold per lesson | 3 | Score at or above which a lesson counts as mastered. |
| Allow repeat attempts | Yes | Gradebook keeps the highest total. |
| Mark grades as AI-provisional | Yes | Flags released grades as awaiting SME validation. |

## Choosing a structure

Both shapes work; pick per course.

**One activity per lesson** (`L01` in one activity, `L02` in the next) gives a
gradebook column per lesson, lets you release lessons as the course progresses,
and matches a lesson → reading → assessment rhythm.

**One activity for several lessons** (`L01,L02,L03`, or blank for all thirteen)
runs as a single sitting with automatic progression, one gradebook column
holding the total, and a course-level judgement at the end. This is the closer
fit for an end-of-course mastery interview.

## Updating the questions

Re-upload a newer question-set file on an existing activity and it picks up the
revised questions and rubrics. Nothing else changes and existing attempts are
left alone. This is the intended path for keeping the assessment current as
lessons and educational objectives are revised.

## What the learner never sees

The evidence guide, misconception list and insufficient-evidence conditions are
sent to the model and shown to anyone with `mod/masteryagent:viewreports`. They
are never rendered to a learner, and the agent is instructed not to restate
them or answer the question on the learner's behalf.

## Instructor report

**View attempts** lists every attempt with its total, how many lessons closed,
and its status. Opening one shows the per-lesson score table, the full
transcript, the evidence ledger the agent maintained, and the rubric behind
every lesson — which is what a subject matter expert needs in order to validate
or overturn the agent's judgement.

## Known limitations

- **No backup/restore support.** `FEATURE_BACKUP_MOODLE2` is declared false, so
  these activities are skipped by course backup rather than silently corrupting
  one. This is the first thing to add for production use.
- **No web services or mobile app support.** The conversation is a plain form
  post, which works in any browser but does not appear in the Moodle app.
- **Synchronous model calls.** Each reply waits on the provider, typically a few
  seconds. There is no queue or retry. A thirteen-lesson sitting is a long
  session; consider splitting it if learners are on poor connections.
- **Rubrics are agent-generated drafts.** The supplied question set is marked
  `DRAFT_PENDING_HUMAN_VALIDATION`. Keep the AI-provisional flag on until an SME
  has validated the rubric.

## Licence

GPL v3 or later, matching Moodle.
