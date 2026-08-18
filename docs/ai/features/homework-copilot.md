# Instructor Homework Copilot (P2)

Status: **shipped, disabled by default.** Instructor-facing, drafting
only, and structurally incapable of grading.

> Help instructors write better feedback faster. The instructor decides
> what the student receives.

## What it is

An instructor reviewing a submitted piece of homework clicks **Generate
draft**. A queued run sends the assignment context and the student's
written submission — redacted — to the configured AI provider, validates
the response against a schema, and shows the instructor a draft. The
instructor uses it as a starting point or discards it, edits freely, and
publishes through the existing review flow.

## What it is not

- It does not grade. There is no score, mark, percentage or pass/fail
  **anywhere in the schema**, so a model has nowhere to put one — the
  guarantee is structural, not a matter of the prompt being obeyed.
  `homework_assignments.grade` stays the instructor's, written only by
  `ReviewHomeworkAction`.
- It does not publish. `homework_assignments.feedback` is written by one
  class in the entire application (asserted by test), from what the
  instructor typed.
- It does not run on its own. No listener, no observer, no scheduled
  command and no submission hook may reach it (asserted by test).
- It does not change homework status, learning-plan progress, or notify
  anyone.
- The student never sees a draft, at any point.

## Privacy posture — the honest version

P1 sent short third-party review excerpts. **P2 sends the student's own
extended writing, and that is a harder problem.** Redaction removes
names and contact details; it cannot make an essay about a family
holiday non-identifying. No amount of pattern matching fixes that.

So the protection here is **posture, not technique**:

| Control | Effect |
|---|---|
| Instructor-initiated only | Work is sent because the tutor who can already read it asked |
| One submission per request | No bulk export, no batch, no sweep |
| Only submitted, not-yet-reviewed work | Enforced at request AND re-checked when the job runs |
| Explicit confirmation dialog | States exactly what is sent before anything leaves |
| Never stored by the AI layer | No prompt, no response, no submission copy |
| Attachments never sent | Only the fact one exists — no OCR, no file upload |
| Size cap (6,000 characters) | A truncated submission is declared to the model, never silently cut |

`HomeworkCopilotInput` is the boundary: a field not on that DTO cannot
reach a provider.

**Never sent:** the student's name, id or email; the instructor's
identity; assignment/booking/lesson/plan identifiers; due dates or
lateness; **grades or prior feedback** (assessment must not be
anchored); payment, wallet or earnings data; attachment bytes.

**Sent:** subject, academic level, assignment title and brief, and the
redacted submission text.

Redaction runs through the shared `AiTextRedactor` (contact patterns,
known-participant names, residual digit runs, length cap). Instructor-
authored fields are redacted too — instructors routinely write "Mira,
focus on..." in a brief.

## Instructor workflow

```
Instructor opens a submitted homework → Review
  → "Generate draft"  (confirmation stating what is sent)
  → queued; the screen never waits on the provider
  → draft appears: summary, strengths, improvements, suggested wording
  → "Use as a starting point"  → fills the editor, records provenance
     or "Discard"
  → instructor edits freely
  → "Mark Reviewed"  → publishes THEIR text, and their grade
```

`Used` means the draft was placed in the editor. It never means the text
was published — what reaches the student is whatever the instructor
submitted.

If AI is off, unconfigured, over budget or unreachable, the button
reports why inline and the review screen keeps working exactly as before.

## Prompt and schema

`homework_feedback:v1` (`HomeworkFeedbackPrompt`) — frozen; new wording
means a v2. The system prompt forbids grades and implied grades
("close to full marks"), forbids stating correctness as settled fact,
forbids comparison to other students, requires the stated academic
level, and requires the model to say so when the work cannot be assessed.

`HomeworkFeedbackSchema`: `summary`, `strengths[]`, `improvements[]`,
`suggested_feedback`, `confidence`, `requires_instructor_review`.
`additionalProperties: false`.

`requires_instructor_review` is **hardcoded true by the domain**, not
read from the model — unlike P1, where the model may raise the review
bar, there is no case in which an unreviewed draft may reach a student,
so it is not a value the model gets a vote on.

Registered into the P0 registries by `HomeworkServiceProvider`, so
`app/Ai` never learns this feature exists.

## Storage

`homework_ai_feedback_drafts` — a **separate table from the published
feedback**, deliberately. Holds the validated draft, its provenance and
its lifecycle. `source_snapshot` records shape and size only (subject,
level, character count, whether an attachment existed) — never the
submission. `ai_run_id` links to P0 telemetry for model, tokens, cost
and latency.

No grade, score or pass column exists, and none may be added.

## Authorization

`HomeworkAiFeedbackDraftPolicy` — the assigning instructor, and only
them. `generate` additionally requires the work to be submitted and not
yet reviewed.

Students are denied permanently: a draft is unreviewed model output
about their work, and it exists so the tutor can correct it first.

There is no admin surface either — this mirrors
`HomeworkAssignmentPolicy`, which grants staff nothing. `super_admin`
bypasses via `Gate::before()` exactly as it does for the assignment
itself. No new Shield permissions were added; the homework domain is
policy-gated, not permission-gated.

## Cost

Every generation creates an `ai_runs` row (feature, model, prompt
version, tokens, estimated cost, latency) and is subject to the P0
daily/monthly budget, checked before dispatch for a fast, legible
message and again inside execution authoritatively.

A homework draft is the most expensive AI call the platform makes so
far — a full submission plus a long output. Watch cost-per-draft before
raising limits.

## Enabling it

1. Configure the provider and key in **Settings → AI Platform**.
2. Turn on `FeatureSettings::ai_enabled`.
3. Turn on `AiSettings::homework_assistant_enabled` (the P0 capability
   flag — no new flag was added).
4. Ensure a worker runs the `ai` queue, or drafts sit "Drafting…"
   forever.

With `provider = fake` the whole pipeline runs with no external call.

## Future improvements

- **Attachment support** would need OCR and a much larger data-protection
  decision. Currently the model is told an attachment exists and to tell
  the tutor to review it directly.
- **Draft usefulness feedback** (was this helpful? did you use it?) —
  `used_at`/`discarded_at` already give a crude signal; an explicit
  rating would make prompt revisions measurable, as with P1.
- **Per-feature budget** — `ai_runs.feature_key` already supports it.

## Files

| Concern | Location |
|---|---|
| Domain | `app/Homework/Copilot/**` |
| Model | `app/Models/HomeworkAiFeedbackDraft.php` |
| Policy | `app/Policies/HomeworkAiFeedbackDraftPolicy.php` |
| UI | `app/Livewire/Frontend/Instructor/HomeworkList.php` + its Blade view |
| Wiring | `app/Providers/HomeworkServiceProvider.php` |
| Tests | `tests/Feature/Homework/Copilot/**` |
