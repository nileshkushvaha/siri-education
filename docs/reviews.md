# Reviews (Student Review Eligibility)

Phase 17H foundation: durable, verified eligibility for a student to
review the instructor of a completed lesson. This phase creates,
revokes, restores, and expires eligibility windows only — it does not
implement review submission, ratings, moderation, public display, or
notifications. No Review/Rating/Feedback domain existed before this
phase (`StudentReviewsController` was a "coming soon" placeholder).

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

**Policy snapshot, not live settings**: every eligibility record stores
the exact settings values in force when it was opened, in `metadata`.
A later settings change (e.g. `review_window_days` 14 → 30) never
retroactively changes an already-open window's `expires_at` or mode.

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

## Authorization

`LessonReviewEligibilityPolicy` — `view()` allows only the eligibility's
own `student_id` or a permissioned staff member; `viewAny()` is
staff-only. **No `create`/`update`/`delete` ability is defined at
all** — undefined abilities deny by default, so an instructor (or
anyone else) cannot create, alter, or delete eligibility through any
policy path. All writes happen exclusively through
`ReviewEligibilityService` and its actions (system-level, bypassing
the policy layer by design — the same pattern as every other
system-authored record in this codebase). Permissions
(`ViewAny:LessonReviewEligibility`, `View:LessonReviewEligibility`)
are seeded by `ReviewPermissionSeeder` to the `manager` role.

## Events

`LessonReviewEligibilityOpened` (creation and restore-after-override),
`LessonReviewEligibilityExpired`, `LessonReviewEligibilityRevoked` —
all `ShouldDispatchAfterCommit`. No notification or review-submission
listeners are attached in this phase.

## Audit

Every transition is recorded via `AuditTrailService::logSystem()`
under the `reviews` log name: `review_eligibility_opened`,
`review_eligibility_restored`, `review_eligibility_revoked`,
`review_eligibility_flagged_manual_review`,
`review_eligibility_expired`.

## Folder structure

```
app/Reviews/
├── Actions/       OpenLessonReviewEligibilityAction, ReevaluateLessonReviewEligibilityAction, ExpireLessonReviewEligibilityAction
├── Contracts/      ReviewEligibilityServiceInterface, LessonReviewEligibilityRepositoryInterface
├── Enums/          LessonReviewEligibilityMode, LessonReviewEligibilityStatus, ReviewableLessonType
├── Events/         LessonReviewEligibilityOpened/Expired/Revoked
├── Exceptions/      ReviewEligibilityException
├── Repositories/   LessonReviewEligibilityRepository
└── Services/       ReviewEligibilityService

app/Models/LessonReviewEligibility.php
app/Policies/LessonReviewEligibilityPolicy.php
app/Listeners/Reviews/  (thin triggers — no eligibility logic)
app/Providers/ReviewServiceProvider.php (bootstrap/providers.php)
```

## Deployment runbook

1. `php artisan migrate --force` — creates `lesson_review_eligibilities`
   and seeds the `reviews.*` settings defaults.
2. `php artisan db:seed --class=ReviewPermissionSeeder --force` —
   mandatory: without it only `super_admin` can view eligibility
   records at all.
3. Queue worker (`notifications` queue) — the two outcome listeners are
   queued.
4. Scheduler cron — gates `reviews:expire-eligibility` (hourly).

## Deferred (do not build yet)

Review submission (ratings, written text), review moderation, public
profile/review display, instructor responses, review notifications,
homework, learning-plan progress, and all frontend UI.

## Tests

`tests/Feature/Reviews/LessonReviewEligibilityTest.php` — creation
matrix (paid/demo × policy), non-completed outcomes create nothing,
kill switch, window timing from completion time (not command time),
duplicate/concurrent idempotency, override revoke/manual-review/restore,
policy-snapshot immutability, expiration (and its used/revoked
exclusion), authorization (student-only view, instructor fully
excluded), and the guarantee that no review/rating/wallet/payment/
earning/settlement record or notification is ever touched.
