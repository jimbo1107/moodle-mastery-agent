# AY27 8670 Mastery Evaluation Pipeline

Three pieces that turn a course PDF into a conversational mastery assessment inside Moodle.
Built for the MCU-NPS AI Learning Initiatives use case *PME Course Material Mastery
Evaluation Agent* (EWSDEP 8670 prerequisite course).

```
agent1 - rubric agent      agent2 - question generator      masteryagent
┌────────────────────┐    ┌────────────────────────┐    ┌──────────────────────────┐
│ Coursebook PDF     │───►│ Rubric + coursebook    │───►│ Teacher uploads the JSON │
│ → Educational      │    │ → one open-ended       │    │ → agent interviews the   │
│   Objectives       │    │   question + evidence  │    │   learner, scores each   │
│ → 1–5 mastery      │    │   guide per lesson     │    │   lesson, writes to the  │
│   rubric           │    │                        │    │   gradebook              │
└────────────────────┘    └────────────────────────┘    └──────────────────────────┘
  ..._Draft_Mastery_          ..._question_set_            Moodle plugin
   Rubrics.json                AY27_8670.json              (mod_masteryagent)
```

| Directory | What it is |
| --- | --- |
| `agent1 - rubric agent/` | Prompt + coursebook that produce the mastery rubric. Describes mastery; writes no questions. |
| `agent2 - question generator/` | Prompt, spec, template and validator that turn the rubric into the plugin's question-set JSON. Writes questions; assesses no one. |
| `masteryagent/` | The Moodle activity plugin. Interviews the learner, scores each lesson, pushes the total to the gradebook. |
| `masteryagent.zip` | Packaged plugin, ready for **Site administration → Plugins → Install plugins**. |

Each directory has its own README with the details — how to run the agent, the JSON contract,
and the plugin's requirements (Moodle 4.5+, a configured AI provider with **Generate text**).

Stages are decoupled: rerun agent 1 and agent 2 whenever the coursebook changes, then upload
the new question set. Nothing in the plugin is course-specific.
