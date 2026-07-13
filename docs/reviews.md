# Reviews (Eligibility, Submission & Moderation)

Phase 17H built durable, verified eligibility for a student to review
the instructor of a completed lesson (create/revoke/restore/expire
windows only). Phase 17I added submission: an eligible student submits
a rated, optionally-tagged review — a public-review candidate or
private feedback, per the eligibility's mode. Phase 17J adds the
moderation/publication lifecycle on top: automatic moderation of every
newly submitted review under a configurable model, plus administrator
approve/reject/hide/restore/archive actions. None of the three phases
implement public display, rating aggregates, instructor responses, or
notifications. No Review/Rating/Feedback domain existed before Phase
17H (`StudentReviewsController` was a "coming soon" placeholder).

## Data model

`lesson_review_eligibilities` — **exactly one row per (lesson,
student)**, enforced by a unique index. Outcome corrections transition
the *same* row (open → revoked, open → expired, used → manual_review,
revoked → open again) — nothing is ever deleted or duplicated; every
prior state is appended to `history` before a transition.

- Participants/context: `lesson_id`, `booking_id`, `student_id`,
  `instructor_id`, `lesson_type` (`paid`/`demo`).
- Decision: `eligibility_mode` (`public_review`/`private_feedback`),
  `status` (`open`/`used`/`expired`/`revoked`/`manual_review`).
- Window: `opens_at`, `expires_at` — both computed from the lesson's
  **finalized completion time** (`outcome_finalized_at` ??
  `completed_at`), never from when a command happens to run.
- Provenance: `outcome_snapshot`, `source_outcome_version` (the
  lesson's `outcome_version` at creation/restore).
- Lifecycle: `used_at`, `revoked_at` + `revoked_reason`, `version`
  (bumped on every transition), `history` (JSON array of prior
  snapshots), `metadata` (JSON policy snapshot — see below).

## Settings (`ReviewSettings`, Spatie group `reviews`)

| Setting | Default | Meaning |
|---|---|---|
| `reviews_enabled` | **false** | Master switch — off blocks eligibility for every lesson type |
| `paid_lesson_reviews_enabled` | true | Gates paid-lesson eligibility (only matters once `reviews_enabled` is on) |
| `demo_review_policy` | `private_only` | `disabled` \| `private_only` \| `public` |
| `review_window_days` | 14 | Days after completion an open window stays valid |
| `rating_min` / `rating_max` | 1 / 5 | Inclusive bounds for overall and every dimension rating |
| `written_review_required` | false | Whether submitted text is mandatory |
| `review_min_length` / `review_max_length` | 10 / 2000 | Character bounds on the sanitized text |
| `rating_dimensions_enabled` | true | Whether the five optional per-dimension ratings may be submitted at all |
| `review_max_tags` | 5 | Maximum tags a single submission may select |
| `moderation_model` | `risk_based` | `pre_moderation` \| `post_moderation` \| `risk_based` |
| `auto_publish_clean_reviews` | true | Master override — off means nothing auto-publishes regardless of model |

**Policy snapshot, not live settings**: every eligibility record stores
the exact settings values in force when it was opened, in `metadata`;
every submitted review likewise stores the rating/text/tag settings in
force at submission time (`settings_snapshot`) and the moderation
settings in force when automatic moderation last evaluated it
(`moderation_snapshot`). A later settings change (e.g.
`review_window_days` 14 → 30, `rating_max` 5 → 10, or `moderation_model`
`risk_based` → `pre_moderation`) never retroactively changes an
already-open window, an already-submitted review, or a past moderation
decision.

## Eligibility matrix

Consumed from `LessonOutcomeFinalized` (queued listener →
`ReviewEligibilityService::handleOutcomeFinalized`) — only the
`Completed` outcome is ever eligible; every other outcome is a
structural no-op (instructor no-show / technical-issue complaint flows
stay entirely separate from standard reviews, per the SRS).

| Condition | Result |
|---|---|
| `reviews_enabled = false` | none |
| Completed, paid, `payment_status = Paid`, `paid_lesson_reviews_enabled = true` | Open, Public Review |
| Completed, paid, but paid reviews disabled | none |
| Completed, demo (`payment_status = NotRequired`), `demo_review_policy = private_only` | Open, Private Feedback |
| Completed, demo, `demo_review_policy = public` | Open, Public Review |
| Completed, demo, `demo_review_policy = disabled` | none |
| Lesson/booking participants don't match (`booking.attendee_id`/`host_id`) | none |
| Student No-Show / Instructor No-Show / Both Absent / Technical Issue / Cancelled | none |

"Paid" vs "demo" is read from `booking.type.is_paid` (`BookingType`),
the existing single source of truth — never inferred from price or
payment amount.

## Outcome overrides

Consumed from `LessonOutcomeOverridden` →
`ReevaluateLessonReviewEligibilityAction`, entirely on the existing row:

- **Completed → non-completed**, window still `Open` → **Revoke**
  (`revoked_at`, `revoked_reason` = the override's own reason, history
  appended). Requires a reason by construction — the override event
  always carries one.
- **Completed → non-completed**, window already `Used` → **flag
  `manual_review`** instead of revoking; `used_at` is preserved and the
  record is never hidden or deleted — a submitted review must survive
  the correction for a human to decide.
- **Non-completed → Completed** → **open or restore**: a lesson with no
  prior record opens fresh (via the same `OpenLessonReviewEligibilityAction`
  used on first completion); a `Revoked` record is **restored on the
  same row** (status back to `Open`, window recalculated from the
  *corrected* finalization timestamp, a fresh policy snapshot, version
  bumped) — never a second row. If current policy no longer grants
  eligibility, the revoked record is left exactly as-is. `Expired`,
  `Used`, and `ManualReview` records are never regressed back to
  `Open` by a correction.

## Idempotency & concurrency

Exactly one row per (lesson, student) is enforced at the database
level; `OpenLessonReviewEligibilityAction` checks for an existing row
first and additionally catches `UniqueConstraintViolationException` on
insert, so a genuine concurrent race between two listener deliveries
still resolves to one row. `ExpireLessonReviewEligibilityAction`
row-locks and rechecks status before transitioning, so a duplicate
sweep run or a race with a status change elsewhere can never
double-expire or clobber a settled record.

## Expiration

`reviews:expire-eligibility` (scheduled **hourly**,
`withoutOverlapping()` + `onOneServer()`) expires only `Open` records
past `expires_at`, cursored via `lazyById()` in fixed-size batches
(never the full table in memory), with per-record failure isolation.
`Used`, `Revoked`, and `ManualReview` records are structurally
unreachable by the sweep's query.

## Submission (Phase 17I)

`StudentReviewService::submit()` → `SubmitLessonReviewAction` — the
single writer of `lesson_reviews`. One review per eligibility (and,
structurally, per booking — a 1-to-1 lesson has one student), both
DB-unique. `review_mode` is always copied from the eligibility itself;
a submission payload can never choose or change lesson, instructor, or
mode.

### Schema (`lesson_reviews`)

`eligibility_id` (unique), `lesson_id`, `booking_id` (unique),
`student_id`, `instructor_id`, `review_mode`, `overall_rating` +
5 optional dimension ratings (teaching quality, communication,
punctuality, preparedness, learning value), `content` (sanitized plain
text only), `tags` (JSON snapshot of `{key, label}`), `status`,
`submitted_at`, `settings_snapshot` (JSON), `sanitization_metadata`
(JSON — flags only, never raw text), `version`. Nothing is ever
physically deleted; `status` is an open string vocabulary
(`StudentReviewStatus` already reserves `Hidden`/`Rejected`/`Archived`
alongside the three this phase produces) so a future moderation phase
needs no schema change to add those transitions or edit history.

### Statuses this phase produces

- **`Submitted`** — clean public-review candidate, invisible until a
  future moderation/publication phase.
- **`Private`** — private feedback; never enters public moderation,
  never contributes to a future rating aggregate, never reaches the
  instructor unless a later policy explicitly allows it.
- **`Flagged`** — the content sanitizer detected something unsafe;
  held for moderation regardless of public/private mode. Nothing is
  ever auto-approved, rejected, hidden, or published from a flag.

### Submission rules (revalidated under a lock, every time)

Eligibility must be `Open`, the acting user must be the eligibility's
own student, `now` must be within `[opens_at, expires_at]`, the mode
must not be `Disabled`, and the lesson/booking must still reference
the exact same student/instructor the eligibility was opened for.
Duplicate/concurrent submissions against an already-`Used` window
return the existing review idempotently (`applied: false`) rather than
erroring — matching every other idempotent operation in this codebase.
Any other non-`Open` status (`Expired`/`Revoked`/`ManualReview`) is a
hard reject.

### Rating, text, and tag validation

Overall rating is required at the type level
(`SubmitStudentReviewData::$overallRating` is a non-nullable
constructor parameter) and range-checked against
`[rating_min, rating_max]`; the five dimension ratings are optional but
range-checked when present, and rejected outright if
`rating_dimensions_enabled` is off. Written text is sanitized *first*,
then length-checked against `[review_min_length, review_max_length]`
on the **sanitized** result (what's actually stored); missing text is
only an error when `written_review_required` is on. Tags are deduped,
capped at `review_max_tags`, and every key must resolve to an
`is_active` `ReviewTag` whose `applicable_modes` includes the
eligibility's mode — any invalid/inactive/inapplicable key rejects the
whole submission (dedicated `review_tags` table, not the CMS `Tag`
model, which is an unrelated Post-tagging system).

### Contact-leakage & unsafe-content sanitization

`ReviewContentSanitizer::sanitize()` (`app/Reviews/Support/`) runs
before anything is validated or stored: `<script>`/`<style>` blocks are
removed wholesale (not just de-tagged), remaining HTML is stripped,
and emails, phone-shaped digit runs (7+ digits after separators are
stripped), links (including bare meeting-provider domains like
`zoom.us`/`meet.google.com`/`t.me`), and `@handles` are each redacted
to `[redacted]` and flagged (`ReviewContentFlag`). Payment-solicitation
and promotional-spam phrases are keyword-matched and redacted the same
way. Detection never blocks submission — it flips `status` to
`Flagged` — and **the raw matched text never reaches storage, the
audit trail, or logs**: `sanitization_metadata` and every audit
property carry only flag category values (e.g. `'email'`,
`'phone_number'`), never the original string.

### Atomicity & idempotency

`SubmitLessonReviewAction` locks the eligibility, revalidates,
validates input, creates the review, and marks the eligibility `Used`
(`used_at`, `history` appended, `version` bumped) — all in one
transaction. A validation failure (bad rating, bad tag, etc.) rolls
back the entire attempt, so the eligibility is provably never
left partially transitioned. The eligibility row lock plus the
DB-unique `eligibility_id`/`booking_id` indexes make concurrent
submission attempts resolve to exactly one review.

### Submission authorization

`LessonReviewEligibilityPolicy::submitReview()` — the eligibility's own
`student_id` only, with **no staff bypass** (nobody submits *as* the
student, not even an administrator). `LessonReviewPolicy::view()`
mirrors the eligibility policy (own student or permissioned staff);
no create/update/delete ability exists on either policy — all content
writes are exclusively through `SubmitLessonReviewAction`.

## Moderation & Publication (Phase 17J)

Adds `Published` to `StudentReviewStatus` (Hidden/Rejected/Archived
were reserved in 17I, unused until now) and a guarded state machine —
`canTransitionTo()`, mirroring `LessonStatus`/`InstructorEarningStatus`:

```
Submitted → Published | Flagged | Rejected
Flagged   → Published | Private | Rejected
Published → Hidden
Hidden    → Published | Archived
Private   → Rejected  | Archived
Rejected, Archived → (terminal)
```

`TransitionReviewStatusAction` is the single writer of `status` —
every automatic and admin path funnels through it, throwing
`InvalidReviewTransitionException` on any transition the table above
doesn't allow. Moderation **never edits** the student's rating, text,
or tags — status (and `moderated_at`/`moderated_by`/
`moderation_reason`/`moderation_snapshot`) only. History is preserved
through the existing activity log (`AuditTrailService`), not a new
column — no review row is ever physically deleted.

### Automatic moderation

`StudentReviewSubmitted` → queued listener →
`ModerateSubmittedReviewAction`, which **only ever acts on a review
still `Submitted`** (a duplicate/replayed event, or one arriving after
a human already decided, is an idempotent no-op) and **never touches**
`Private` or `Flagged` reviews — private feedback must never become
public automatically, and anything Phase 17I's sanitizer already
flagged always waits for a human, in every model. It never resanitizes
text or re-validates rating/tag rules — it only reads what 17I already
stored (`review_mode`, `sanitization_metadata.had_unsafe_content`) plus
the *current* moderation settings, snapshotting the latter onto the
review (`moderation_snapshot`) so a later settings change can never
retroactively reinterpret a past decision.

| `moderation_model` | `auto_publish_clean_reviews` | Clean `Submitted` review |
|---|---|---|
| `pre_moderation` | any | stays `Submitted` (always needs a human) |
| `post_moderation` | true | → `Published` |
| `risk_based` | true | → `Published` |
| any | false | stays `Submitted` (master override) |

A `Submitted` review is, by construction, already the "safe" branch of
Phase 17I's own risk split (unsafe content is `Flagged` at submission,
never `Submitted`) — so `post_moderation` and `risk_based` currently
produce identical automatic outcomes; the distinction is preserved as
a configuration option for a future phase that might score risk
independently of 17I's sanitizer.

### Administrator actions (`ReviewModerationService`)

`approve` (target derives from `review_mode` — `Published` for a
public candidate, `Private` for private feedback; reason optional for
this one "straightforward" action), `reject`, `hide`, `restore`,
`archive` (all require a non-empty reason). Every action: permission
check → row lock → **idempotent no-op if already at the target
status** → state-machine-guarded transition → audit → after-commit
event. A decision that conflicts with a status someone else already
changed (not "already there", but genuinely incompatible) throws
`InvalidReviewTransitionException` rather than silently applying —
concurrent admins resolve to exactly one winner, loudly.

### Permissions

Two new abilities, both staff-only with **no student or instructor
bypass**: `moderate` (approve/reject/restore/archive) and `hide`
(separately permissioned — pulling a *live* review is treated as more
sensitive than any other transition). Seeded as `Moderate:LessonReview`
and `Hide:LessonReview`.

## Authorization

`LessonReviewEligibilityPolicy` — `view()` allows only the eligibility's
own `student_id` or a permissioned staff member; `viewAny()` is
staff-only. **No `create`/`update`/`delete` ability is defined at
all** — undefined abilities deny by default, so an instructor (or
anyone else) cannot create, alter, or delete eligibility through any
policy path. All writes happen exclusively through
`ReviewEligibilityService`/`StudentReviewService` and their actions
(system/participant-level, bypassing the policy layer by design for
the system paths — the same pattern as every other system-authored
record in this codebase). Permissions
(`ViewAny:LessonReviewEligibility`, `View:LessonReviewEligibility`,
`ViewAny:LessonReview`, `View:LessonReview`, `Moderate:LessonReview`,
`Hide:LessonReview`) are seeded by `ReviewPermissionSeeder` to the
`manager` role.

## Events

`LessonReviewEligibilityOpened` (creation and restore-after-override),
`LessonReviewEligibilityExpired`, `LessonReviewEligibilityRevoked`,
`StudentReviewSubmitted`, `StudentReviewPublished`,
`StudentReviewRejected`, `StudentReviewHidden`, `StudentReviewRestored`,
`StudentReviewArchived` — all `ShouldDispatchAfterCommit`, each fired
exactly once per actual transition (never on an idempotent no-op). No
notification, aggregate, or public-display listeners are attached in
any of the three phases.

## Audit

Every transition is recorded via `AuditTrailService` under the
`reviews` log name: `review_eligibility_opened`,
`review_eligibility_restored`, `review_eligibility_revoked`,
`review_eligibility_flagged_manual_review`,
`review_eligibility_expired`, `student_review_submitted` (via
`logUser`, since a student always causes it — properties carry ids,
mode, status, and content-flag *categories* only, never raw text),
`review_moderation_evaluated` / `review_auto_published` (system,
automatic moderation), `review_approved` / `review_rejected` /
`review_hidden` / `review_restored` / `review_archived` (via `logUser`
— actor, previous/new status, reason, version).

## Folder structure

```
app/Reviews/
├── Actions/        OpenLessonReviewEligibilityAction, ReevaluateLessonReviewEligibilityAction,
│                   ExpireLessonReviewEligibilityAction, SubmitLessonReviewAction,
│                   ModerateSubmittedReviewAction, TransitionReviewStatusAction
├── Contracts/      ReviewEligibilityServiceInterface, LessonReviewEligibilityRepositoryInterface,
│                   StudentReviewServiceInterface, LessonReviewRepositoryInterface,
│                   ReviewModerationServiceInterface
├── DTOs/           SanitizedReviewContent, SubmitStudentReviewData, SubmitReviewResult
├── Enums/          LessonReviewEligibilityMode, LessonReviewEligibilityStatus, ReviewableLessonType,
│                   StudentReviewStatus, ReviewContentFlag
├── Events/         LessonReviewEligibilityOpened/Expired/Revoked, StudentReviewSubmitted,
│                   StudentReviewPublished/Rejected/Hidden/Restored/Archived
├── Exceptions/     ReviewEligibilityException, ReviewValidationException, InvalidReviewTransitionException
├── Repositories/   LessonReviewEligibilityRepository, LessonReviewRepository
├── Services/       ReviewEligibilityService, StudentReviewService, ReviewModerationService
└── Support/        ReviewContentSanitizer

app/Models/LessonReviewEligibility.php, LessonReview.php, ReviewTag.php
app/Policies/LessonReviewEligibilityPolicy.php, LessonReviewPolicy.php
app/Listeners/Reviews/  (thin triggers — no eligibility/moderation logic)
app/Providers/ReviewServiceProvider.php (bootstrap/providers.php)
```

## Deployment runbook

1. `php artisan migrate --force` — creates `lesson_review_eligibilities`,
   `review_tags`, `lesson_reviews` (+ its Phase 17J moderation columns),
   and seeds the `reviews.*` settings defaults.
2. `php artisan db:seed --class=ReviewPermissionSeeder --force` —
   mandatory: without it only `super_admin` can view or moderate
   eligibility/review records at all.
3. `php artisan db:seed --class=ReviewTagSeeder --force` — idempotent
   default tag catalog; without it no tags exist to select.
4. Queue worker (`notifications` queue) — the outcome listeners and
   the automatic-moderation listener are all queued.
5. Scheduler cron — gates `reviews:expire-eligibility` (hourly).

## Deferred (do not build yet)

Public profile/review display, rating aggregates, instructor
responses/visibility, review notifications, review editing (the model
is deliberately prepared for it — open `status` vocabulary, `version`
column — but no code path edits), homework, learning-plan progress,
and all frontend UI.

## Tests

`tests/Feature/Reviews/LessonReviewEligibilityTest.php` (Phase 17H) —
creation matrix (paid/demo × policy), non-completed outcomes create
nothing, kill switch, window timing from completion time (not command
time), duplicate/concurrent idempotency, override
revoke/manual-review/restore, policy-snapshot immutability, expiration
(and its used/revoked exclusion), authorization (student-only view,
instructor fully excluded), and the guarantee that no
review/rating/wallet/payment/earning/settlement record or notification
is ever touched.

`tests/Feature/Reviews/StudentReviewSubmissionTest.php` (Phase 17I) —
public/private/flagged submission outcomes, eligibility revalidation
(expired/revoked/duplicate-used), authorization (another student,
instructor-on-behalf), rating requiredness + range + dimension-toggle
validation, written-text length bounds, contact-leakage redaction +
unsafe-HTML stripping with a dedicated raw-content-never-logged check,
tag validation + dedup, submission/eligibility-used atomicity on
failure, concurrent-submission idempotency, and the guarantee that
nothing publishes, aggregates, notifies, or touches any
financial/booking/lesson-outcome/earning record.

`tests/Feature/Reviews/ReviewModerationTest.php` (Phase 17J) — all
three automatic-moderation models (clean and flagged, public and
private), admin approve/reject/hide/restore/archive including the
mode-derived approve target, reason requirements, invalid-transition
rejection, duplicate-decision idempotency, conflicting-concurrent-
decision resolution, content immutability across moderation, audit
records and exactly-once event dispatch, and the guarantee that
nothing publishes to an aggregate, notifies, or touches any
financial/booking/lesson/earning record.

### A pre-existing seeder fix surfaced during Phase 17J

`ReviewPermissionSeeder` created permission rows and then called
`Role::givePermissionTo()` before clearing Spatie's in-memory
permission cache — if anything (e.g. a policy check inside
`StudentReviewService::submit()`, exercised earlier in the same test)
had already primed that cache as empty, the newly created rows were
invisible to `givePermissionTo()`, which threw `PermissionDoesNotExist`
despite the rows existing. Fixed by clearing the cache immediately
after creating the permissions, before assigning them to the role (in
addition to the existing clear at the end). This is a real,
previously-latent defect — no prior test happened to prime the cache
before seeding ran — not a workaround.
