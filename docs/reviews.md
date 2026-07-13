# Reviews (Eligibility, Submission & Moderation)

Phase 17H built durable, verified eligibility for a student to review
the instructor of a completed lesson (create/revoke/restore/expire
windows only). Phase 17I added submission: an eligible student submits
a rated, optionally-tagged review — a public-review candidate or
private feedback, per the eligibility's mode. Phase 17J adds the
moderation/publication lifecycle on top: automatic moderation of every
newly submitted review under a configurable model, plus administrator
approve/reject/hide/restore/archive actions. Phase 17K adds a durable,
incrementally-maintained instructor rating aggregate — overall
average, rating distribution, dimension averages, paid/demo counts —
kept in sync with the moderation lifecycle via idempotent event
reconciliation, plus a repair/rebuild tool. Phase 17L surfaces all of
that on the existing public instructor profile page: a privacy-safe
review list with masked reviewer identity and a derived Verified
Lesson badge, reusing the Phase 17K aggregate for the summary. Phase
17M lets any active user report a published public review and lets an
authorized administrator resolve it — every resolution that changes
review visibility delegates to the *same* `ReviewModerationService`
Phase 17J already built, never a second moderation system. None of the
six phases implement instructor responses, notifications, quality
alerts, marketplace ranking, or reporting/analytics dashboards. No
Review/Rating/Feedback domain existed before Phase 17H
(`StudentReviewsController` was a "coming soon" placeholder).

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

## Instructor Rating Aggregate Foundation (Phase 17K)

Adds a durable, incrementally-maintained rating summary per instructor
— **backend foundation only**: no public profile UI, review lists,
student identity display, quality alerts, marketplace ranking,
instructor responses, or notifications. Structurally mirrors the
Wallet/`WalletLedgerEntry` pattern (materialized counter + durable
ledger + mutation guard), the closest existing analogue for "durable
review-contribution record, aggregate version, rebuilt-at timestamp,
never let sums/counts go negative."

### Schema

`instructor_rating_aggregates` — **one row per instructor** (unique
`instructor_id`): `eligible_review_count`, `overall_rating_sum`,
`rating_distribution` (JSON, e.g. `{"5": 12, "4": 3}`),
`paid_review_count` / `demo_review_count`, five dimension
`{name}_sum` / `{name}_count` column pairs (teaching quality,
communication, punctuality, preparedness, learning value),
`last_published_review_at`, `version`, `rebuilt_at`. **No average
column exists anywhere** — `overallAverage()`,
`teachingQualityAverage()`, etc. are accessor methods computed fresh
as `round(sum / count, 2)` on every read (`null` when count is 0),
so rounding error can never compound across writes.

`review_rating_contributions` — **one row per `LessonReview`** (unique
`review_id`): `included` (boolean — whether this review currently
counts), snapshots of the overall + 5 dimension ratings *at the moment
it was applied*, `lesson_type`, `applied_review_version`, `applied_at`
/ `removed_at`, `version`. This ledger is what makes every lifecycle
event idempotent: reconciliation always compares "should this review
count right now" against `included`, so `remove()` subtracts the
*ledger's* snapshotted values, never the review's possibly-changed
live values.

Both models guard their sum/count columns exactly like `Wallet`
guards its balance: any direct `save()`/`update()` touching a guarded
column outside `InstructorRatingAggregate::withAuthorizedMutation()`
throws `ReviewAggregateException` — the only legitimate writers are
`ReconcileReviewContributionAction` and
`RebuildInstructorRatingAggregateAction`.

### Inclusion rules (`ReviewContributionEligibility::qualifies()`)

The single shared predicate used by both the incremental reconciler
and the full rebuild, so they can never disagree:

| Condition | Included? |
|---|---|
| `status = Published`, `review_mode = PublicReview`, rating present and within the review's own `settings_snapshot` bounds | yes |
| `Submitted` / `Flagged` (not yet published) | no |
| `Private` (private feedback) | no |
| `Hidden` / `Rejected` / `Archived` | no |
| Rating outside the review's *own* historical `rating_min`/`rating_max` snapshot | no |

Reads only the review's own stored state — never current live
`ReviewSettings` — so a later scale change (e.g. `rating_max` 5 → 10)
never reinterprets an already-published review's contribution.
Private feedback and not-yet-published reviews are structurally
unreachable by this predicate; there is no code path that lets them
affect a public aggregate.

### Lifecycle-event reconciliation

All five listeners (`ReconcileRatingContributionOnStudentReview{Published,Hidden,Restored,Rejected,Archived}`)
are identically thin — construct-inject the service, `handle()` calls
`reconcile($event->review)`, nothing else. Every one of them funnels
into the same `ReconcileReviewContributionAction::execute()`:

1. Lock the review fresh from the DB (never trust the event payload).
2. Lock-or-create the review's contribution row.
3. Compute `shouldContribute = ReviewContributionEligibility::qualifies($review)`.
4. If `shouldContribute === $contribution->included`, **return — no-op,
   aggregate untouched.**
5. Otherwise lock-or-create the instructor's aggregate and add or
   remove the delta.

Because the desired state is always recomputed fresh rather than
"add"/"remove" being a verb baked into the caller, duplicate events,
replayed events, and events arriving out of order all converge to the
same result with no per-event special-casing: publishing twice adds
once, hiding twice removes once, a stale in-memory review snapshot
still reconciles against current DB truth. Rejected/Archived reviews
are structurally never `included=true` under normal operation (see
the status transition table above — `Published` can only reach
`Rejected`/`Archived` via `Hidden` first, which already removed the
contribution), so their "remove only if previously contributing"
requirement is satisfied by the same generic no-op path, no bespoke
code.

### Idempotency & concurrency protections

- Row-locking (`lockForUpdate()`) inside `DB::transaction()` for every
  mutating path — review, contribution, and aggregate are all locked
  before any read informs a decision.
- `clampedSubtract()` never lets a sum/count go negative — clamps to
  0 and logs `rating_aggregate_drift_detected` (instructor id, field,
  attempted value only — never review content) rather than corrupting
  the row or throwing mid-transaction.
- The guarded-column mutation hook on `InstructorRatingAggregate`
  makes it structurally impossible for anything outside
  `InstructorRatingAggregateService` to silently drift a sum/count.

### Rebuild (`reviews:rebuild-aggregates`)

A **repair tool, not the primary update path** — the event-driven
reconciler keeps aggregates correct under normal operation; this
exists for suspected drift or after direct data fixes. **Deliberately
not scheduled.**

```
php artisan reviews:rebuild-aggregates              # every instructor with ≥1 review
php artisan reviews:rebuild-aggregates --instructor=42   # one instructor only
```

`RebuildInstructorRatingAggregateAction` locks the aggregate, cursors
the instructor's full `LessonReview` set via `lazyById()` (never
loaded fully into memory — the *distinct instructor id list* driving
the batch is a separate, inherently small/bounded query that's safe
to fetch eagerly), recomputes every sum/count/distribution/dimension
total from scratch using the exact same `ReviewContributionEligibility::qualifies()`
predicate, replaces the aggregate wholesale, and repairs the
contribution ledger to match (skipping a ledger row write entirely
when it's already converged, so a re-run on unchanged data produces
no spurious version churn). The aggregate row itself is always
rewritten with a fresh `rebuilt_at`, even when no drift is found — a
rebuild is provably recorded as having run. Per-instructor failures
are caught and logged (`Log::warning`) without aborting the batch, so
one bad record never blocks every other instructor.

### Rating calculation rules

`overall_average = overall_rating_sum / eligible_review_count`,
`{dimension}_average = {dimension}_sum / {dimension}_count` —
independently, since a review with a missing dimension rating is
excluded from that dimension's count entirely (never counted as
zero). Distribution bucket totals always sum to
`eligible_review_count` by construction (every included review
increments exactly one bucket, every removed review decrements
exactly one). A zero-review instructor's aggregate row may not even
exist yet — `summaryFor()` returns `reviewCount: 0`,
`averageRating: null` (never `0`), `ratingDistribution: []`.

### Read-only summary (`InstructorRatingSummaryData`)

`InstructorRatingAggregateService::summaryFor(int $instructorId)` —
for future public-profile/dashboard use. Returns instructor id,
review count, average rating, rating distribution, per-dimension
averages, paid/demo counts. **Never** exposes private-feedback data,
student identity, internal moderation reasons, raw review text, or an
internal quality score — those simply don't exist anywhere in the
aggregate/contribution schema, so there's nothing to accidentally
leak.

### Audit

`AuditTrailService::logSystem()` under the `reviews` log name:
`rating_contribution_added`, `rating_contribution_removed`,
`rating_aggregate_drift_detected` (clamp events), `rating_aggregate_rebuilt`
(every rebuild call, with `before`/`after`/`drifted` — a boolean
flagging whether the rebuild actually changed anything).

### Isolation

No booking, lesson, payment, wallet, earning, or
instructor-compensation record is read for writing or ever mutated by
this phase. Rating aggregates do not feed marketplace ranking,
notifications, or any quality-alert system in this phase — those
tables/paths simply don't exist yet.

## Public Review Display (Phase 17L)

Surfaces Phase 17K's rating aggregate and a privacy-safe review list
on the **existing** public instructor profile page (`instructors.show`,
`InstructorController::show()` → `InstructorService::publicProfile()`
→ `resources/views/instructors/show.blade.php`). No second profile
page, no new route. `InstructorService::ratingsFor()` (previously a
permanent `['average' => null, 'count' => 0]` stub used by both the
instructor card component and the profile snapshot) now delegates to
`InstructorRatingAggregateService::summaryFor()` — the first real
consumer of the Phase 17K read path.

### `PublicInstructorReviewService`

The exclusive public read boundary for reviews (`app/Reviews/Services/
PublicInstructorReviewService.php`, bound to
`PublicInstructorReviewServiceInterface`):

- `summaryFor(User $instructor): InstructorRatingSummaryData` — a
  direct pass-through to `InstructorRatingAggregateService::summaryFor()`.
  A second, duplicate "public" summary DTO was considered and rejected:
  the Phase 17K DTO already excludes every private field this phase
  also needs to exclude, so returning it directly avoids recalculating
  or re-wrapping anything.
- `paginatedReviewsFor(User $instructor, int $perPage = 10):
  LengthAwarePaginator<PublicInstructorReviewData>` — queries via
  `LessonReviewRepository::publicPaginatedForInstructor()` and maps
  every row through `PublicInstructorReviewData::fromReview()` before
  it leaves the service. An Eloquent `LessonReview` (or its
  eligibility/lesson/student relations) never reaches a public Blade
  view. `$perPage` is a code-level default, never accepted from the
  request — only `page` (standard Laravel pagination) is client
  input, and it drives a bounded `LIMIT/OFFSET` query, never an
  in-memory fetch-then-slice.
- A defensive `isPubliclyVisible()` check (same fields
  `InstructorService::publicProfile()` already gates on — role,
  `isActive()`, `profile_visibility = public`, bookable
  `instructor_status`) returns an empty paginator without querying the
  database at all for a non-public instructor. The controller's own
  gate already blocks the page entirely in that case; this exists so a
  future direct caller of the service can never accidentally surface a
  private instructor's reviews.

### Query (`LessonReviewRepository::publicPaginatedForInstructor()`)

```sql
WHERE instructor_id = ? AND status = 'published' AND review_mode = 'public_review'
ORDER BY moderated_at DESC, id DESC
```

An explicit `select()` (id, eligibility_id, lesson_id, student_id,
instructor_id, the 6 ratings, content, tags, submitted_at,
moderated_at) means `moderation_reason`, `moderation_snapshot`,
`moderated_by`, `settings_snapshot`, `sanitization_metadata`, and
`booking_id` never even reach PHP memory for a public request —
belt-and-suspenders alongside the DTO-mapping boundary. Eager loads are
column-restricted the same way (`student:id,first_name,status`,
`student.profile:id,user_id,student_status`,
`eligibility:id,lesson_id,lesson_type,status`, `lesson:id,outcome`).

### Inclusion rules

| Condition | Publicly visible? |
|---|---|
| `status = Published`, `review_mode = PublicReview`, belongs to this instructor | yes |
| Private feedback (`review_mode = PrivateFeedback`) | never — hardcoded out of the query |
| `Submitted` / `Flagged` (not yet published) | no |
| `Hidden` / `Rejected` / `Archived` | no |
| Belongs to a different instructor | excluded by `WHERE instructor_id = ?` |

Instructor approval/active/public-visibility is enforced twice: once
by the existing `InstructorService::publicProfile()` abort logic
(unchanged), and defensively again inside
`PublicInstructorReviewService`.

### Student identity masking

New setting `reviews.public_review_identity_mode` (`ReviewSettings::
$public_review_identity_mode`, default `first_name_initial`) —
`anonymous` | `first_name_initial` | `first_name_only`. Computed
**fresh at read time** by `PublicReviewerIdentity::label()`
(`app/Reviews/Support/`) — never stored on the review, so a later
setting change reshapes every past review's displayed label without a
single review row changing, satisfying "changing the setting must not
alter stored review history" by construction rather than by a
migration.

| Mode | Available student | Unavailable student |
|---|---|---|
| `anonymous` | "Verified Student" | "Verified Student" |
| `first_name_initial` | "N\*\*\*" | "Verified Student" |
| `first_name_only` | "Nilesh" (first name only — surname never read) | "Verified Student" |

"Unavailable" = `! $student->isActive()` or
`$student->profile?->student_status === StudentStatus::Archived` —
covers both an inactive account and an archived student profile.
Email, phone, country, profile URL, account id, and photo are never
read by `PublicReviewerIdentity` or `PublicInstructorReviewData` at
all — there is no code path by which they could leak, not just a
Blade-level omission.

### Verified Lesson badge (`PublicReviewVerification::isVerified()`)

Never a stored, client-controlled flag — derived fresh on every read:

```
eligibility->status === Used  AND  lesson->outcome === Completed
```

This catches the case a stored flag would miss: if an admin later
overrides a lesson's outcome away from `Completed` (Phase 17H's
outcome-override flow), a **Used** eligibility becomes
**ManualReview**, not Used — so a review whose underlying lesson
outcome was corrected after the fact stops showing "Verified Lesson"
on its very next page render, with no code change and no batch job.
The review itself keeps displaying (moderation status is a separate,
untouched concern) — only the verification badge reacts. Demo reviews
published under `demo_review_policy = public` show "Verified Demo
Lesson" instead of "Verified Lesson" (derived from
`eligibility->lesson_type`) — never the booking's payment status or
price.

### `PublicInstructorReviewData` (the only shape a public view ever sees)

`reviewerLabel`, `overallRating`, `dimensionRatings` (array, missing
dimensions stay `null`), `content`, `tags` (the review's own
`{key, label}` snapshot — already validated/active at submission
time), `submittedAt`, `verifiedLesson`, `lessonType`. **Never**:
student id, email, phone, moderation reason/snapshot, booking/lesson
id, internal quality score, or raw eligibility state.

### Ordering & pagination

`moderated_at DESC, id DESC` — deterministic even when many reviews
publish within the same second (UUIDv7 primary keys are
time-ordered, so the `id` tiebreak still reflects creation order).
Standard Laravel `LengthAwarePaginator` (`?page=`), default 10 per
page, rendered with the existing `<x-ui.pagination>` component
(already used by the instructor listing page — no new pagination
convention introduced).

### Cache

No caching was added for this phase. No existing convention for
per-record public-page response/query caching exists anywhere in this
codebase (`docs/cache-manager.md` covers admin-triggered
`cache:clear`/`optimize` operations only, not response caching), and
the spec is explicit: don't invent one just for this phase. Every
request queries live, so a hidden/restored/archived review is
reflected on the very next page load — there is no stale-cache window
to invalidate.

### Deferred

Quality alerts, marketplace ranking changes, instructor responses,
review reporting (Phase 17M), notifications, and an admin-facing
reviews UI — none of this phase.

## Review Reporting & Administrative Resolution (Phase 17M)

Lets an authenticated, active user report a published public review
and lets a permissioned administrator investigate and resolve it —
**no second moderation system**: every resolution that should change
the review's public visibility delegates to the existing
`ReviewModerationService` (`hide`/`reject`/`archive`/`restore`), the
exact same methods Phase 17J's admin actions already use. This phase
adds a parallel, append-only *report* record, not a parallel review
state machine.

### Schema (`review_reports`)

One row per (reporter, review, reason) — many rows may exist per
review. `review_id`, `reporter_id`, `reason`, `explanation` (sanitized
plain text only, ≤1000 chars), `status`, `submitted_at`, `reviewed_at`
/ `reviewed_by`, `resolution_reason`, `resolution_action`, `version`.
Nothing here is ever physically deleted or edited outside the guarded
status transition — mirrors `lesson_reviews`' own append-only
convention exactly.

**Reasons** (`ReviewReportReason`): Fake or Misleading, Abusive
Language, Personal Information, Off-Platform Solicitation, Hate or
Harassment, Spam, Irrelevant Content, Privacy Concern, Other.

**Statuses** (`ReviewReportStatus`), state-machine guarded exactly
like `StudentReviewStatus`:

```
Pending      → UnderReview | Upheld | Dismissed | Duplicate | Withdrawn
UnderReview  → Upheld | Dismissed | Duplicate | Withdrawn
Upheld, Dismissed, Duplicate, Withdrawn → (terminal)
```

`Withdrawn` is reserved vocabulary — no submission or resolution path
in this phase produces it (same precedent as `Hidden`/`Rejected`/
`Archived` being reserved on `LessonReview` in Phase 17I, unused until
17J implemented them).

**Resolution actions** (`ReviewReportResolutionAction`): `NoAction`,
`HideReview`, `RejectReview`, `ArchiveReview`, `RestoreReview` — every
non-`NoAction` value maps 1:1 to a `ReviewModerationService` method
call and never drives a direct `lesson_reviews.status` write.

### Reporting eligibility (`SubmitReviewReportAction`)

A review may be reported only when, checked fresh under a row lock:

| Condition | Reportable? |
|---|---|
| `status = Published`, `review_mode = PublicReview`, `reviews.review_reporting_enabled = true` | yes |
| Private feedback | no |
| `Submitted` / `Flagged` (not yet published) | no |
| `Hidden` / `Rejected` / `Archived` | no |
| Reporter already has an active (`Pending`/`UnderReview`) report against this review for the *same* reason | no — throws `DuplicateReviewReportException` |
| `review_reporting_enabled = false` | no — throws `ReviewNotReportableException` |

A different reporter, or the same reporter with a *different* reason,
may always submit a separate report. A report never deletes, hides, or
edits the review by itself — only an explicit admin resolution can.

### Submission flow

`ReviewReportService::submit()` → policy check
(`LessonReviewPolicy::report()`) → `SubmitReviewReportAction::execute()`,
all inside one transaction: lock the review → re-verify eligibility →
reject a duplicate active report → sanitize the explanation via the
**same** `ReviewContentSanitizer` Phase 17I's review-content pipeline
uses (HTML/scripts stripped, emails/phones/links/handles redacted,
payment/spam keywords flagged) → truncate to 1000 chars → create →
audit (`review_reported`, properties carry only ids, the reason value,
and sanitized flag *categories* — never raw explanation text or
contact details) → dispatch `ReviewReported` after commit.

### Administrative resolution (`ReviewReportService`)

| Method | Transition | Reason required? | Delegates to moderation? |
|---|---|---|---|
| `startReview()` | Pending → UnderReview | no | no |
| `uphold()` | → Upheld | **yes** | optionally: Hide / Reject / Archive |
| `dismiss()` | → Dismissed | **yes** | optionally: Restore |
| `markDuplicate()` | → Duplicate | no | never |
| `markRemainingPendingAsDuplicate()` | bulk → Duplicate | yes | never |

`uphold()` rejects `RestoreReview` as an action (upholding a report
never restores visibility); `dismiss()` rejects `Hide`/`Reject`/
`Archive` (dismissing a report never reduces visibility) — both throw
`ReviewValidationException` on an invalid action-for-outcome pairing.
Every method: permission + row-lock → **idempotent no-op if already at
the target status** (repeating an identical resolution changes
nothing, including silently discarding a differing reason on the
repeat call) → state-machine-guarded transition → the matching
`ReviewModerationService` call, if any → audit → after-commit event. A
resolution that conflicts with a status already reached another way
(e.g. dismissing an already-Upheld report) throws
`InvalidReviewReportTransitionException` rather than silently
applying — exactly the same conflict-resolution shape as
`ReviewModerationService`.

`markRemainingPendingAsDuplicate()` loops every other
Pending/UnderReview report for the same review through the same
per-report `markDuplicate()` path (no bespoke bulk-authorization logic
— each iteration gets the same policy + idempotency guarantees), for
the case in section 7 of the spec: once one report already caused a
hide/reject/archive, the rest can be swept to Duplicate without
resolving each individually by hand.

### Authorization

| Ability | Who | Notes |
|---|---|---|
| `report` (`LessonReviewPolicy`) | Any active user with `Report:LessonReview` | The only ability in this domain that is **not** staff-only — seeded to `student` and `instructor`, not `manager` |
| `viewAny`/`view` (`ReviewReportPolicy`) | `ViewAny:ReviewReport` / `View:ReviewReport` | Staff only |
| `resolve` (`ReviewReportPolicy`) | `Resolve:ReviewReport` **and** `$user->id !== $report->review->instructor_id` | Staff only, with an explicit instructor-cannot-resolve-their-own-review exclusion even if a future role change ever granted an instructor the permission. Students never hold `Resolve:ReviewReport` at all, so they're denied structurally. |

### Reporter privacy

The reporter's identity and explanation are never exposed to the
review's author or instructor, and never reach a public response:
`PublicInstructorReviewData` (Phase 17L) has no report-related field
at all — reports simply aren't part of that read path — and
`ReviewReportAdminData` (the only DTO that carries a reporter
reference) is built exclusively from within an already
permission-gated (`View:ReviewReport`/`ViewAny:ReviewReport`) context.

### Integration with rating aggregates & public display

A report, by itself, never touches `instructor_rating_aggregates` or
the public review list — only the resulting `lesson_reviews.status`
change does, through the *exact same* Phase 17K event-driven
reconciliation and Phase 17L query filters every other moderation path
already goes through. Upholding a report with `HideReview` therefore
removes the review from both the public profile and the rating
aggregate in exactly one step, with no bespoke "report count" signal
ever feeding either.

### Read projection (`ReviewReportAdminData`)

For a future admin UI — review summary (status, rating, a 200-char
content excerpt), report reason/explanation/status, reporter id,
submission/resolution timestamps, resolution reason/action. **Never**:
student contact details, booking/payment information, instructor
compensation, raw audit payloads, or unrelated lesson data.

### Deferred

Quality alerts, instructor quality scores, notifications (the five
events above have zero listeners attached in this phase — reserved
vocabulary), review analytics, instructor responses, marketplace
ranking changes, and Filament/admin UI.

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
notification, or public-display listeners are attached in any of the
four phases. Phase 17K attaches exactly one new consumer to each of
the five `StudentReview*` moderation events —
`ReconcileRatingContributionOnStudentReview{Published,Hidden,Restored,Rejected,Archived}`
— all thin, all delegating to `InstructorRatingAggregateService::reconcile()`.

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
│                   ModerateSubmittedReviewAction, TransitionReviewStatusAction,
│                   ReconcileReviewContributionAction, RebuildInstructorRatingAggregateAction,
│                   SubmitReviewReportAction, TransitionReviewReportStatusAction
├── Contracts/      ReviewEligibilityServiceInterface, LessonReviewEligibilityRepositoryInterface,
│                   StudentReviewServiceInterface, LessonReviewRepositoryInterface,
│                   ReviewModerationServiceInterface, InstructorRatingAggregateServiceInterface,
│                   InstructorRatingAggregateRepositoryInterface, ReviewRatingContributionRepositoryInterface,
│                   PublicInstructorReviewServiceInterface, ReviewReportRepositoryInterface,
│                   ReviewReportServiceInterface
├── DTOs/           SanitizedReviewContent, SubmitStudentReviewData, SubmitReviewResult,
│                   InstructorRatingSummaryData, PublicInstructorReviewData,
│                   SubmitReviewReportData, ReviewReportAdminData
├── Enums/          LessonReviewEligibilityMode, LessonReviewEligibilityStatus, ReviewableLessonType,
│                   StudentReviewStatus, ReviewContentFlag, ReviewReportReason, ReviewReportStatus,
│                   ReviewReportResolutionAction
├── Events/         LessonReviewEligibilityOpened/Expired/Revoked, StudentReviewSubmitted,
│                   StudentReviewPublished/Rejected/Hidden/Restored/Archived, ReviewReported,
│                   ReviewReportReviewStarted, ReviewReportUpheld, ReviewReportDismissed,
│                   ReviewReportMarkedDuplicate
├── Exceptions/     ReviewEligibilityException, ReviewValidationException, InvalidReviewTransitionException,
│                   ReviewAggregateException, ReviewNotReportableException, DuplicateReviewReportException,
│                   InvalidReviewReportTransitionException
├── Repositories/   LessonReviewEligibilityRepository, LessonReviewRepository,
│                   InstructorRatingAggregateRepository, ReviewRatingContributionRepository,
│                   ReviewReportRepository
├── Services/       ReviewEligibilityService, StudentReviewService, ReviewModerationService,
│                   InstructorRatingAggregateService, PublicInstructorReviewService, ReviewReportService
└── Support/        ReviewContentSanitizer, ReviewContributionEligibility,
                    PublicReviewVerification, PublicReviewerIdentity

app/Models/LessonReviewEligibility.php, LessonReview.php, ReviewTag.php,
           InstructorRatingAggregate.php, ReviewRatingContribution.php, ReviewReport.php
app/Policies/LessonReviewEligibilityPolicy.php, LessonReviewPolicy.php, ReviewReportPolicy.php
app/Listeners/Reviews/  (thin triggers — no eligibility/moderation/aggregate logic)
app/Console/Commands/RebuildInstructorRatingAggregates.php  (reviews:rebuild-aggregates)
app/Providers/ReviewServiceProvider.php (bootstrap/providers.php)

Phase 17L touches the existing Instructor domain, not a new one:
app/Http/Controllers/Instructor/InstructorController.php  (show() now also passes reviewSummary/reviews)
app/Services/Instructor/InstructorService.php              (ratingsFor()/stats() wired to the real aggregate)
resources/views/instructors/show.blade.php                 (Reviews & Ratings section added)
```

## Deployment runbook

1. `php artisan migrate --force` — creates `lesson_review_eligibilities`,
   `review_tags`, `lesson_reviews` (+ its Phase 17J moderation columns),
   `instructor_rating_aggregates`, `review_rating_contributions`,
   `review_reports`, and seeds the `reviews.*` settings defaults
   (including Phase 17L's `public_review_identity_mode` and Phase
   17M's `review_reporting_enabled`).
2. `php artisan db:seed --class=ReviewPermissionSeeder --force` —
   mandatory: without it only `super_admin` can view/moderate
   eligibility/review records, report a review, or resolve a report.
3. `php artisan db:seed --class=ReviewTagSeeder --force` — idempotent
   default tag catalog; without it no tags exist to select.
4. Queue worker (`notifications` queue) — the outcome listeners, the
   automatic-moderation listener, and the five rating-reconciliation
   listeners are all queued. The five Phase 17M report events dispatch
   after commit but currently have **no** queued listeners at all
   (reserved for a future notification/quality-alert phase).
5. Scheduler cron — gates `reviews:expire-eligibility` (hourly).
   `reviews:rebuild-aggregates` is **not** scheduled — run it manually
   only after suspected aggregate drift or a direct data fix.

## Deferred (do not build yet)

Instructor responses/visibility toggles, review notifications
(including report-related ones), review editing (the model is
deliberately prepared for it — open `status` vocabulary, `version`
column — but no code path edits), quality alerts, instructor quality
scores, marketplace ranking, review-report analytics, an admin-facing
reviews/reports UI, homework, learning-plan progress, and all frontend
UI outside the instructor profile page itself.

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

`tests/Feature/Reviews/InstructorRatingAggregateTest.php` (Phase 17K)
— single contribution add, every non-contributing status
(submitted/flagged/private/rejected/archived-without-prior-
contribution), hidden removal, restored re-addition exactly once,
duplicate published/hidden reconciliation non-double-counting, a
stale in-memory review snapshot still converging to current DB
truth, overall average and rating-distribution-totals-equal-count
correctness, missing-dimension exclusion, dimension average
correctness, paid vs demo separate counting, zero-review
null-average/empty-distribution, historical rating-scale snapshot
surviving a live settings change, a publish→hide→restore→hide
sequence staying consistent, rebuild reproducing the same result as
incremental reconciliation, rebuild repairing a manually drifted
aggregate (with an audited `drifted: true` record), rebuild and the
`reviews:rebuild-aggregates` command both being idempotent across two
runs, the summary DTO exposing no private fields, no public-listing
table being created, no notification/marketplace-ranking side effect,
no financial/booking/lesson/earning record change, and a Phase
17H–17J moderation regression check (flag → admin approve → publish
still works, and now also aggregates).

`tests/Feature/Reviews/PublicInstructorReviewDisplayTest.php` (Phase
17L) — aggregate average/count shown on the profile page, published
public review displayed, private feedback / submitted / flagged /
hidden / rejected / archived reviews all excluded, a review belonging
to a different instructor excluded, default identity masking
(`N***`), `anonymous` mode (`Verified Student`), `first_name_only`
mode never revealing a surname, email/phone/review-id absence, an
archived student falling back to `Verified Student`, the Verified
Lesson badge appearing for a normal completed lesson and disappearing
after an outcome override reclassifies the lesson away from
`Completed` (while the review itself keeps displaying), the displayed
average matching `PublicInstructorReviewService::summaryFor()`
exactly (never recalculated), a zero-review empty state (never a `0`
rating), deterministic newest-first ordering, pagination page-size and
page-2 correctness, a hidden review never reappearing across repeated
requests (proving there's no stale-cache window), the DTO's field
list containing no id/moderation-reason keys, price never appearing on
the page, and existing profile actions (favorite button, booking
links, Instructor Snapshot) still rendering.

`tests/Feature/Reviews/ReviewReportingTest.php` (Phase 17M) — a
published public review can be reported; private/submitted/hidden/
rejected/archived reviews cannot; an invalid reason string never maps
to `ReviewReportReason`; the explanation is sanitized (script/email/
phone all stripped) and the raw unsafe text never reaches the audit
trail; a duplicate active report by the same reporter+reason is
rejected while a different reporter or a different reason both
succeed; an unauthorized user and an instructor resolving a report
about their own review are both denied; the full admin lifecycle
(start review, dismiss with a reason, uphold-and-hide, an invalid
uphold/dismiss action combination, a missing resolution reason) all
behave correctly; hiding via a report resolution is provably the same
`ReviewModerationService::hide()` call an admin would make directly
(same audit event); the resulting hide removes the review from both
`PublicInstructorReviewService` and the rating aggregate in one step;
a dismissed report leaves the review untouched; conflicting concurrent
resolutions throw while a repeated identical resolution is an
idempotent no-op that never overwrites the original reason; remaining
pending reports can be bulk-marked duplicate after one is resolved;
reviews and reports are never physically deleted; reporter identity
and explanation never appear in a public DTO or on the profile page;
no notification/quality-alert table exists; no financial/lesson record
changes; and a Phase 17J–17L regression check (flag → approve →
publish → aggregate → public page all still work).

### A Blade compiler pitfall discovered during Phase 17L

Adding a new block-form `@php ... @endphp` directive anywhere in
`instructors/show.blade.php` — even one as trivial as `@php $x = 1;
@endphp` — broke compilation of the *entire* file with a misleading
`ParseError: unexpected end of file, expecting "elseif" or "else" or
"endif"`, but **only** in combination with the file's pre-existing
inline `@php($isFavorite = ...)` single-line directive elsewhere in
the same template. Neither form was individually at fault, and the new
section compiled perfectly in isolation — the interaction only
reproduced in the full-file context, diagnosed by bisecting with
`Blade::compileString()` + `php -l` on incrementally larger slices of
the real file. The fix was to avoid introducing any `@php` directive
(block or inline) in new/edited Blade code entirely: values that would
have needed a `@php` block (the dimension-label lookup, the per-star
distribution count) were moved to plain inline `{{ }}` expressions or
computed once in the controller and passed to the view
(`InstructorRatingSummaryData::dimensionLabels()`), and a boolean gate
that would have needed a precomputed collection
(`$activeDimensions->isNotEmpty()`) was rewritten as an inline
`collect(...)->filter()->isNotEmpty()` expression with `@continue`
skipping null dimensions inside the loop instead. This is a
codebase-specific Blade-compiler fragility to be aware of, not a
one-off typo — avoid adding new `@php` directives to this file (or
introduce them by replacing the existing inline one, not alongside
it).

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
