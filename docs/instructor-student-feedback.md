# Instructor-to-Student Lesson Feedback (Phase 17Q)

Private, lesson-linked feedback an instructor records about a
student's participation after a lesson completes — a distinct,
opposite-direction concept from the Reviews domain (`docs/reviews.md`),
which is a student rating an instructor. Nothing here is public, none
of it feeds a rating or aggregate, and nothing is deleted.

## Why a new domain, not Reviews

Audited before writing any code and confirmed no overlap with:

- **Reviews** (`app/Reviews/`) — student-about-instructor, publishable, rating-bearing.
- **Homework** (`HomeworkAssignment.feedback`) — grading feedback on one specific assignment submission, not general lesson-level engagement/attendance/attitude observations.
- **Learning Plans** — long-term, milestone-based plan progress reviews, not per-lesson.
- **Attendance disputes / technical-issue reports** — correcting what happened, not describing how the student engaged.

No authoritative "instructor rates a student" concept existed anywhere
in the codebase, so a new domain, `app/Feedback/`, was created —
mirroring the `app/Reviews/`/`app/Quality/` folder convention, not a
new controller-only feature bolted onto an existing domain.

## Data model

`instructor_student_feedback` — one row per (lesson, instructor); a
lesson has exactly one assigned instructor, so this is the same
guarantee as `unique(lesson_id)` while staying explicit. Columns:
`lesson_id`, `booking_id`, `student_id`, `instructor_id`,
`outcome_snapshot` (finalized `LessonOutcome` at submission),
`source_outcome_version`, `attendance_status_snapshot` (finalized
`LessonAttendanceStatus` at submission — display only), seven nullable
sanitized-plain-text observation columns (`attendance_observation`,
`preparedness_observation`, `homework_completion_observation`,
`engagement_observation`, `learning_attitude_observation`,
`areas_needing_support`, `private_notes`), `sanitization_metadata`
(flags only, keyed by field — never raw text), `submitted_at`,
`version`, timestamps. **No numeric or sentiment score of any kind** —
the SRS defines feedback topics, not a rating scale, so none was
invented. `App\Models\InstructorStudentFeedback` (`app/Models/`, not
`app/Feedback/` — models stay in the shared `app/Models/` namespace
throughout this codebase).

## Eligibility (`SubmitInstructorStudentFeedbackAction::assertEligible()`)

Re-validated against a **row-locked** `Lesson` inside the same
transaction as the insert — never trusted from a snapshot read before
the lock:

1. `$lesson->instructor_id === $instructor->id` — only the assigned instructor.
2. The instructor's account is active and holds the `instructor` role — the same basic account-standing check every other instructor-facing controller in this codebase already performs (`hasRole('instructor')`); this phase does **not** additionally gate on `instructor_status` (that is an Earnings-domain payout-eligibility concept — see `InstructorPayoutEligibility` — not a general teaching-authorization rule, and inventing one here was avoided).
3. `$lesson->outcome === LessonOutcome::Completed` and `hasFinalizedOutcome()` — a single check that structurally rejects Pending, StudentNoShow, InstructorNoShow, BothAbsent, TechnicalIssue, and Cancelled lessons all at once.
4. The booking exists and is not `Cancelled` — this codebase has no distinct "invalidated" booking status, so "not cancelled or invalidated" is implemented as `booking->status !== Cancelled`, a structural defense-in-depth check independent of the outcome check above (an outcome can say Completed while a booking is separately cancelled after the fact).
5. `$lesson->student_id === $booking->attendee_id` and `$lesson->instructor_id === $booking->host_id` — the same "participants match their booking" structural guard `OpenLessonReviewEligibilityAction` (Phase 17H) already established.

Demo and paid completed lessons both qualify — no existing SRS-backed
policy excludes either.

`EnsureInstructorWorkspaceAccess` (added later, see `docs/users.md` §
Instructor workspace access) now gates the *route* hosting this
feature (`dashboard.instructor.lessons`) behind
`InstructorStatus::publiclyVisible()` — an instructor outside that set
is redirected before `LessonFeedbackManager` ever mounts, so in
practice they cannot reach the submission UI at all. This is a routing
concern, not an eligibility rule: `assertEligible()` above deliberately
still does not duplicate an `instructor_status` check itself, for the
same reason stated in point 2 — the two layers answer different
questions (can this admin-facing URL be reached vs. does this specific
lesson/instructor/student triple qualify) and must not be conflated.

## Idempotency & concurrency

Two layered defenses, mirroring `OpenLessonReviewEligibilityAction`
exactly:

1. `Lesson::query()->whereKey($id)->lockForUpdate()` inside a
   transaction serializes any concurrent submission for the *same*
   lesson — a second concurrent request blocks until the first commits,
   then its own `findForLessonAndInstructor()` check finds the
   already-created row and returns it unchanged (never a second row,
   never an overwrite).
2. The `(lesson_id, instructor_id)` unique database index is the final
   guarantee — a `UniqueConstraintViolationException` on insert is
   caught and treated as the idempotent outcome (fetch and return the
   winning row), not a failure. A repeated/duplicate submission call
   after the row already exists is answered from the lock+lookup path
   before an insert is even attempted.

Feedback is immutable after creation — the model has no update path,
the policy's `update`/`delete` abilities always return `false`, and
there is no edit or delete control anywhere in the UI.

## Observations & sanitization

Every observation field reuses `ReviewContentSanitizer::sanitize()`
(Phase 17I) and `ReviewContentFlag` unchanged — no second sanitizer was
built. HTML/scripts, emails, phone numbers, external links/meeting
domains, social handles, payment-solicitation phrases, and promotional
spam are stripped or redacted per field; only the flag *categories*
that tripped are stored in `sanitization_metadata` (keyed per field),
never the matched raw text, and the audit entry carries the same
flag-categories-only summary. All seven fields are optional — the
instructor may fill in as few or as many as apply, and a missing field
stays `null`, never an empty-string placeholder.

**No positive/improvement/sentiment classification of any kind** —
every field is a plain sanitized string.

## Finalized attendance — displayed, never overwritten

`attendance_status_snapshot` freezes the lesson's finalized
`student_attendance_status` at submission time (display-only, next to
the instructor's own free-text `attendance_observation`). The
instructor's observation is additive commentary, never a correction —
nothing in this phase writes back to `lessons.student_attendance_status`
or any attendance table.

## Privacy & visibility

Never appears on public instructor profiles, student public profiles,
review cards, rating aggregates, marketplace search, SEO metadata, or
any public API — confirmed by test (feedback text absent from the
public instructor-profile HTML). `InstructorStudentFeedbackData` is an
explicit field allowlist (never a raw Eloquent model) — no student
email/phone/image/age, no payment/wallet/compensation data, no
moderation or quality-alert data of any kind, because none of those
concepts exist in this domain at all.

**Student visibility is an explicitly deferred future policy
decision** — the SRS does not state whether an instructor's private
notes should ever be shown to the student, so nothing here assumes an
answer either way; the policy denies the student outright for now.

## Authorization

`InstructorStudentFeedbackPolicy` — `submit(User, Lesson)` is
relationship-based (`$user->id === $lesson->instructor_id && $user->hasRole('instructor')`),
authorized via Laravel's `[ModelClass::class, $lesson]` array form
(`Gate::authorize('submit', [InstructorStudentFeedback::class, $lesson])`)
since the ability's subject is a `Lesson`, not yet an
`InstructorStudentFeedback` row. `view()` allows the submitting
instructor or a permissioned admin (`View:InstructorStudentFeedback`).
`update()`/`delete()` always return `false` — no role may mutate or
physically delete a record. Permissions
(`ViewAny:InstructorStudentFeedback`, `View:InstructorStudentFeedback`)
are seeded by `FeedbackPermissionSeeder` to `manager` only — submission
itself is relationship-based, not permission-gated, so this seeder
only affects staff *viewing*. No moderation, review-report, or
quality-alert permission is granted through this policy.

## Instructor UI integration

No existing instructor-facing lesson-detail/completion page existed
anywhere in the codebase (confirmed by audit — the frontend Account
Portal had availability, learning plans, payouts, and quality insights
for instructors, but no lesson list at all). The minimum necessary
surface was added, following the same page-shell + embedded
full-page-Livewire-component convention as every other instructor
dashboard page: `InstructorLessonsController::index()` (role-gated) →
`instructor/lessons/index.blade.php` → `<livewire:frontend.instructor.lesson-feedback-manager />`.
The component lists the instructor's own lessons (paginated, newest
first), and shows a "Give feedback" action only for a lesson whose
outcome is Completed and which has no feedback yet; once submitted,
the same row shows "Private feedback submitted" and a read-only detail
view — no edit/delete control. `AccountMenuService` gained one new
`instructor`-audience nav entry ("My Lessons"). No Filament page and
no separate instructor dashboard were created.

## Events

`InstructorStudentFeedbackSubmitted` — `ShouldDispatchAfterCommit`,
fired exactly once per actual creation (never on the idempotent-return
path). No listener is attached in this phase — no notification,
Learning Plan, quality-alert, report, analytics, or scoring
integration.

## Audit

Recorded via `AuditTrailService::logUser()` under the `feedback` log
name (`instructor_student_feedback_submitted`): instructor, lesson id,
booking id, student id, source outcome + version, and
`sanitization_flag_categories` (flags only). Raw feedback text,
student contact information, prohibited content, and payment/
compensation data are never logged.

## Performance

Batch existence lookup
(`InstructorStudentFeedbackServiceInterface::existingForLessons()`)
avoids N+1 on the paginated lesson list — one query for the whole
page, not one per row. The lesson list itself is paginated and
deterministically ordered.

## Folder structure

```
app/Feedback/
├── Actions/        SubmitInstructorStudentFeedbackAction
├── Contracts/      InstructorStudentFeedbackRepositoryInterface, InstructorStudentFeedbackServiceInterface
├── DTOs/           SubmitInstructorStudentFeedbackData, InstructorStudentFeedbackData
├── Events/         InstructorStudentFeedbackSubmitted
├── Exceptions/     InstructorStudentFeedbackException
├── Repositories/   InstructorStudentFeedbackRepository
└── Services/       InstructorStudentFeedbackService

app/Models/InstructorStudentFeedback.php
app/Policies/InstructorStudentFeedbackPolicy.php
app/Providers/FeedbackServiceProvider.php (bootstrap/providers.php)
app/Http/Controllers/Instructor/InstructorLessonsController.php
app/Livewire/Frontend/Instructor/LessonFeedbackManager.php
resources/views/instructor/lessons/index.blade.php
resources/views/livewire/frontend/instructor/lesson-feedback-manager.blade.php
database/seeders/FeedbackPermissionSeeder.php
database/factories/InstructorStudentFeedbackFactory.php
```

## Deployment runbook

1. `php artisan migrate --force` — creates `instructor_student_feedback`.
2. `php artisan db:seed --class=FeedbackPermissionSeeder --force` —
   mandatory: without it only `super_admin` can view feedback records
   as staff (submission itself is unaffected — it is relationship-based).
3. No queue worker changes — `InstructorStudentFeedbackSubmitted` has
   no queued listener in this phase.
4. No scheduler changes.

## Deferred (do not build yet)

Learning Plan linkage and updates, milestone/curriculum updates,
student public ratings or reputation scores, instructor responses to
reviews, review editing, notifications, AI summaries or suggestions,
instructor coaching, marketplace ranking, quality-alert generation
from this feedback, homework-feedback duplication, an admin/Filament
management UI beyond the permission-gated read path already in the
service/policy layer, cross-domain reporting, and — explicitly — a
decision on student visibility of instructor feedback (documented
above as an open future policy question, not assumed).

## Tests

`tests/Feature/Feedback/InstructorStudentFeedbackTest.php` — 36
scenarios: assigned-instructor submission on both completed paid and
demo lessons; every non-Completed outcome (pending, student/instructor/
both no-show, technical issue, cancelled) rejected by the single
outcome check; a booking cancelled after the fact rejected by the
independent structural check; another instructor, the student, and a
guest all denied; exactly one row per lesson; a duplicate submission
returns the original unchanged; a simulated concurrent duplicate
insert hits the unique constraint with exactly one row surviving;
finalized attendance is never overwritten while the instructor's own
observation is still stored; optional fields stay null; HTML/scripts
stripped; contact information redacted; raw prohibited text absent
from the audit log (flag categories only); the submitting instructor
can view their own feedback; another instructor and the student are
both denied; a permissioned admin may view while an unpermissioned one
is denied; feedback text absent from the public instructor profile
page; no change to the rating aggregate, quality alerts, review
eligibility, lesson outcome, booking status, or financial records
(earnings/settlement); no notification sent; no Learning Plan record
touched; an outcome override afterward preserves the original
`outcome_snapshot` and the feedback text unchanged; and a light
Phase 17H–17P regression check.

## Verification results

- `php artisan migrate` — ran cleanly against the dev database (only this phase's migration was pending).
- `php artisan test --env=testing --filter=InstructorStudentFeedbackTest` — 36/36 passed, 53 assertions.
- `php artisan test --env=testing` (full suite) — see final report for the exact count.
- `./vendor/bin/pint --test` — passed (after one auto-fix to import ordering in the new test file).
- `composer validate` — see final report.
- `npm run build` — see final report.
