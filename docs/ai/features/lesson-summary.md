# AI Lesson Summaries (P3)

Status: **shipped, disabled by default.** Instructor-facing, drafting
only, and structurally incapable of deciding progress.

> Help instructors document a lesson faster. The instructor remains
> responsible for the lesson record.

## What it is

After a lesson's outcome is finalised as Completed, its instructor
clicks **Generate AI summary** in their lesson list. A queued run sends
the lesson's structured context — redacted — to the configured provider,
validates the response against a schema, and shows a draft. The
instructor edits it and approves; **their** text becomes the lesson's
summary of record.

## What it is not

- It does not complete lessons or change status, outcome, or attendance.
- It does not touch learning-plan progress, milestones, or goals.
- It does not decide mastery, level, or a grade. The schema has no such
  property, so a model has nowhere to put one.
- It does not write `lessons.completion_notes` — the instructor's own
  note stays their own.
- It does not run automatically. There is no listener on
  `LessonCompleted`, no sweep, no bulk path (asserted by test). Wiring
  it to that event would be two lines and is deliberately not done.
- It is **not shown to students** in this release.

## Data sources — structured records only

| Sent | From |
|---|---|
| Subject, academic level | `Lesson::subject` / `academicLevel` |
| Topic name and description | `Lesson::subjectTopic` |
| Lesson length in minutes | derived from `starts_at`/`ends_at` |
| Instructor's completion note | `lessons.completion_notes` |
| Plan focus and open milestone titles | `StudentLearningPlan` (active plans only) |
| Homework titles and briefs set for the lesson | `HomeworkAssignment` on the same booking |

**No recording data of any kind.** No transcript, no audio, no video,
no meeting artifact. Class recordings exist in this platform
(`docs/recordings.md`); using them is a separate phase with its own
consent and retention decisions. An architecture test asserts the
domain never references the Recording or Meeting domains.

## Privacy boundary

`LessonSummaryInput` is the boundary — a field not on that DTO cannot
reach a provider.

**Never sent:** student and instructor names, ids and contact details;
lesson, booking, plan and homework identifiers; **dates and times** (the
duration is sent, the date is an identifier); prices, earnings and
payment state; attendance and no-show outcomes; homework submissions,
grades or feedback.

**Also never sent: the private instructor-to-student feedback record.**
Every lesson may carry an `InstructorStudentFeedback` row with
observations on the student's engagement, attitude and preparedness. It
would make a richer prompt, and it is an instructor's private assessment
of a child's character gathered for a different purpose. A summary of
what was taught does not need it.

Free text goes through the shared `AiTextRedactor` with the lesson's
participants as name hints. Instructor-authored fields are redacted too
— tutors routinely write the student's name in their own notes.

The instructor note cap (1,000 chars) mirrors the
`lessons.completion_notes` column, so the cap can never silently
truncate a note the platform itself accepted.

## Instructor workflow

```
Lesson outcome finalised as Completed
  → "Generate AI summary"  (confirmation stating what is sent)
  → queued; the screen never waits on the provider
  → draft appears: summary, topics, what went well, suggested practice,
    suggested next focus
  → "Review & approve" opens an editor pre-filled with the draft
     or "Discard"
  → instructor edits freely
  → "Approve summary"  → THEIR text is stored as the record
```

A discarded or failed attempt can be regenerated; the row is reused, so
one lesson never accumulates competing accounts of what happened.

If AI is off, unconfigured, over budget or unreachable, the button
reports why inline and the lesson screen works exactly as before.

## Prompt and schema

`lesson_summary:v1` (`LessonSummaryPrompt`) — frozen. The system prompt
targets this feature's most likely failure: a model handed three sparse
notes writing a fluent, confident paragraph about a lesson that did not
happen that way. It must summarize only what it was given, must not
claim understanding the tutor's note does not state ("practised
factorisation" is not "understands factorisation"), must keep facts and
suggestions separate, and must return empty lists rather than invent
entries.

`LessonSummarySchema`: `lesson_summary`, `topics_covered[]`,
`strengths_observed[]`, `practice_recommendations[]`, `next_focus[]`,
`confidence`, `requires_instructor_review`.
`additionalProperties: false`.

`requires_instructor_review` is hardcoded true by the domain, not read
from the model.

Registered into the P0 registries by `LessonServiceProvider`, so
`app/Ai` never learns this feature exists.

## Storage

`lesson_ai_summaries` — **one row per lesson** (unique index), holding
two texts kept deliberately apart:

- `lesson_summary` + the four lists — what the model drafted;
- `approved_summary` + `approved_by`/`approved_at` — what the instructor
  approved after editing.

Only `approved_summary` is documentation of the lesson. Storing them in
one column would erase the difference between "a model suggested this"
and "a tutor stands behind this", which is the whole point.

No mastery, level, progress, score or grade column exists, and none may
be added — such a column becomes a metric something later charts, which
is how "AI decides progress" happens without anyone deciding to allow
it.

`source_snapshot` records which kinds of context were available, never
their content.

**A deviation worth naming:** the approved summary lives on this row
rather than on a new `lessons` column. `lessons` is a heavily-used table
in the completion, compensation and reconciliation paths, and adding a
free-text column to it for an AI-adjacent feature is a larger change
than this phase warrants. The summary is reachable as
`$lesson->aiSummary`. If a student-facing timeline later needs it, that
phase can decide whether to promote it.

## Authorization

`LessonAiSummaryPolicy`:

| Who | Generate | View | Approve/Discard |
|---|---|---|---|
| The lesson's instructor | ✅ (outcome Completed only) | ✅ | ✅ |
| The student | ❌ | ❌ | ❌ |
| Another instructor | ❌ | ❌ | ❌ |
| Staff with `View:Lesson` | ❌ | ✅ | ❌ |

The student denial is a deliberate break from `LessonPolicy::view()`,
which grants any participant. That is right for the lesson and wrong
here: a summary is the tutor's professional record, and its draft is
unreviewed model output. Staff read summaries exactly as they read the
lesson — no wider, no narrower — but may never approve one: writing up a
lesson is the teaching professional's responsibility, and an admin
approving on their behalf would put a name to a record they did not
make.

No new permissions were added.

## Cost

Every generation writes an `ai_runs` row (feature, model, prompt
version, tokens, cost, latency, requester), under the P0 daily/monthly
budget — checked before dispatch for a fast message and again inside
execution authoritatively. Cheaper than P2: the input is a note and some
labels, not a full submission.

## Enabling it

1. Configure the provider and key in **Settings → AI Platform**.
2. Turn on `FeatureSettings::ai_enabled`.
3. Turn on `AiSettings::lesson_summary_enabled` (the P0 capability flag
   — no new flag was added).
4. Ensure a worker runs the `ai` queue.

## AI limitations

- **Quality tracks the note.** A lesson with a one-line completion note
  produces a thin summary; a lesson with no note, no topic and no
  homework is refused outright rather than guessed at.
- **The model was not there.** It knows only the structured input, which
  is why the prompt forbids inferring what a student understood.
- **Confidence is the model's own certainty**, mostly a function of how
  much the lesson recorded. It is not a measure of the student.

## Future improvements

- Student-visible learning timeline — a separate product decision, not
  authorised by this phase.
- Recording-derived summaries — a separate phase with its own consent
  and retention design.
- Approval feedback (was the draft close?) to make prompt revisions
  measurable, as noted for P1 and P2.

## Files

| Concern | Location |
|---|---|
| Domain | `app/Lessons/Summaries/**` |
| Model | `app/Models/LessonAiSummary.php` |
| Policy | `app/Policies/LessonAiSummaryPolicy.php` |
| UI | `app/Livewire/Frontend/Instructor/LessonFeedbackManager.php` + `lesson-detail-panel.blade.php` |
| Wiring | `app/Providers/LessonServiceProvider.php` |
| Tests | `tests/Feature/Lessons/Summaries/**` |
