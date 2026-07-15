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
Phase 17J already built, never a second moderation system. Phase 17N
detects instructor quality risk (low ratings, no-shows, cancellations,
upheld serious reports) from those same events and records durable,
deduplicated alerts for staff review — recommendations only, never an
automatic suspension, profile change, or compensation/ranking effect.
Phase 17O adds a read-only Filament admin dashboard surfacing all of
the above for operational triage — moderation workload, reports,
quality alerts, and instructor rating health — with every action
delegating to the same services Phases 17J/17M/17N already built.
Phase 17P adds the instructor-facing counterpart — a read-only
"Reviews & Quality" page on the existing Account Portal dashboard
showing an instructor their own rating summary, dimension highlights
and improvement areas, aggregated feedback tags, and recent published
reviews — reusing the Phase 17K aggregate and Phase 17L public-review
projection exactly as-is, with no second aggregate, no AI-generated
content, and no internal quality score or alert visibility exposed to
the instructor. Phase 17R replaces the student "coming soon" reviews
placeholder with a real portal (open opportunities, submission through
the same Phase 17I service, own-review list with statuses) and adds
limited review editing: a configurable window from `submitted_at`,
append-only sanitized revision history, report locks, re-moderation
through the same Phase 17J pipeline, and an exactly-once rating-
contribution swap through the same Phase 17K reconciler. Phase 17S
finally wires the whole domain into the existing (Booking-pattern)
notification pipeline — student/instructor transactional notifications
plus permission-resolved administrator alerts for moderation, reports,
and quality alerts — with a new idempotency-claim table since no
existing mechanism guaranteed "replayed event → one notification".
None of the phases implement instructor responses, review-response
notifications, public quality scores, review deletion, or marketplace
ranking. No Review/Rating/Feedback domain existed before Phase 17H
(`StudentReviewsController` was a "coming soon" placeholder — now the
Phase 17R portal).

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
| `review_editing_enabled` | true | Phase 17R — master switch for limited student review editing |
| `review_edit_window_hours` | 24 | Phase 17R — edit window measured from `submitted_at` only (never publication/moderation/page-load) |

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

Quality alerts (Phase 17N), instructor quality scores, notifications
(the five events above have zero listeners attached in this phase —
reserved vocabulary), review analytics, instructor responses,
marketplace ranking changes, and Filament/admin UI.

## Instructor Quality Alert Foundation (Phase 17N)

A new `App\Quality\*` domain (parallel to `App\Reviews\*`,
`App\Lessons\*`, `App\Booking\*` — Eloquent models still live in
`app/Models/` per the codebase-wide convention) that detects
instructor quality risk from four existing signal sources and records
durable, deduplicated alerts for staff review. **No second review,
lesson, booking, or instructor-performance system was created** —
every detector consumes an existing domain event
(`StudentReviewPublished/Hidden/Rejected/Archived`,
`LessonOutcomeFinalized/Overridden`, `BookingCancelled`,
`ReviewReportUpheld`) and writes only to its own new `quality_alerts`
table.

### Schema (`quality_alerts`)

`instructor_id`, `alert_type`, `severity`, `status`, `source_type` +
`source_id` (a label pair, not a functioning polymorphic relation — no
admin UI consumes it yet), `detection_fingerprint` (**unique** — the
sole dedup mechanism), `triggered_at`, `signal_window_start/end`,
`signal_count`, `threshold_snapshot` (JSON), `summary_metadata` (JSON
— sanitized evidence references only), `needs_reevaluation` +
`reevaluated_at` (a non-destructive staleness flag, separate from
`status`), `assigned_to`, `reviewed_at`/`reviewed_by`, `resolved_at`,
`resolution_action`/`resolution_reason`, `version`. Nothing here is
ever physically deleted.

**Types**: `SingleLowRating`, `RepeatedLowRatings`, `InstructorNoShow`,
`RepeatedInstructorNoShows`, `RepeatedInstructorCancellations`,
`SeriousReviewReport`, `SuspiciousReviewPattern` (reserved — no
detector produces it in this phase, same precedent as
`ReviewReportStatus::Withdrawn`). **Severities**: Low/Medium/High/
Critical, centralized in `QualityAlertSeverityPolicy`. **Statuses**,
guarded exactly like `StudentReviewStatus`/`ReviewReportStatus`:

```
Open        → UnderReview | Resolved | Dismissed | Duplicate | Expired
UnderReview → Resolved | Dismissed | Duplicate | Expired
Resolved, Dismissed, Duplicate, Expired → (terminal)
```

`Expired` is reserved vocabulary, unused in this phase.

### Settings (all on the existing `ReviewSettings`)

`quality_alerts_enabled` (**default false** — every detector's first
line is this guard clause), `low_rating_threshold` (2),
`single_low_rating_alert_enabled` (true), `repeated_low_rating_count`
(3) / `repeated_low_rating_window_days` (30), `repeated_no_show_count`
(2) / `repeated_no_show_window_days` (30), `repeated_cancellation_count`
(3) / `repeated_cancellation_window_days` (30). Every alert stores a
`threshold_snapshot` at creation time (`QualityAlertThresholdSnapshot::capture()`)
— a later settings change never retroactively reinterprets an
already-created alert, the identical discipline every other
settings-snapshot in this domain follows.

### Detection flow

Every "detect" action follows the same shape: guard on
`quality_alerts_enabled` → check the specific signal condition → build
a `QualitySignalData` → hand it to `RecordQualityAlertSignalAction`,
the single writer of new rows. Deduplication is **never** a pre-check
— it's the fingerprint's unique index. Every create attempt actually
runs; a `UniqueConstraintViolationException` is caught and treated as
"a concurrent or replayed detector already won," the identical
convention `OpenLessonReviewEligibilityAction` (Phase 17H) already
uses. This is what makes duplicate events, replayed events, and
concurrent evaluations all converge to exactly one alert with no
locking or pre-existing parent row required.

**Single-occurrence types** (`SingleLowRating`, `InstructorNoShow`,
`SeriousReviewReport`) key their fingerprint on the triggering source
record (`quality-alert:{type}:{instructor}:{source-id}`) — that record
can only ever produce one alert, by construction.

**Repeated/threshold types** key on an **episode number** instead
(`quality-alert:{type}:{instructor}:episode-{n}`), where `n` = 1 +
however many *terminal* alerts of that type already exist for the
instructor. Two concurrent evaluations of the same still-open episode
both compute the same `n` and collide into one row (satisfying
"concurrent processing must create one alert"); once that episode is
resolved/dismissed/marked-duplicate, the next threshold crossing
computes `n+1` and is free to create a genuinely new alert — this is
what "a new alert may be created only for a genuinely new window or
escalation" means concretely, without needing a lockable row to exist
before the first alert does. `InstructorQualityAlertEscalated` fires
alongside `InstructorQualityAlertCreated` specifically when `n > 1` —
the same quality problem recurring after a past resolution.

### Signal rules

| Signal | Source event | Rule |
|---|---|---|
| Low rating | `StudentReviewPublished` | Only `Published` + `PublicReview` reviews; private feedback never triggers anything automatically. Rating ≤ `low_rating_threshold` may create `SingleLowRating`. Count of *actual* low published reviews (never the rounded aggregate average) within the rolling window ≥ `repeated_low_rating_count` creates `RepeatedLowRatings`. |
| Instructor no-show | `LessonOutcomeFinalized` | Only `LessonOutcome::InstructorNoShow` — `StudentNoShow`/`BothAbsent`/`TechnicalIssue`/`Cancelled` are different enum values and structurally excluded, never inferred. Creates `InstructorNoShow`; window count ≥ `repeated_no_show_count` also creates `RepeatedInstructorNoShows`. |
| Instructor cancellation | `BookingCancelled` | Only `BookingActor::Host`-attributed cancellations (`InstructorCancellationAttribution`) — student (`Attendee`), system-expiry/payment-failure (`System`), and admin-correction (`Admin`) cancellations are different actor values, excluded by construction, never by parsing the free-text `cancellation_reason`. No singular per-cancellation alert type exists — only `RepeatedInstructorCancellations` at the window threshold, per spec. |
| Serious review report | `ReviewReportUpheld` | Only 5 reasons count: Personal Information, Off-Platform Solicitation, Hate or Harassment, Abusive Language, Fake or Misleading — Spam/Irrelevant/PrivacyConcern/Other never do, regardless of upheld status. Dismissed/Duplicate reports never reach this action at all (only `Upheld` dispatches it). Fingerprint keys on the *review*, not the report, so several reports against one review — resolved separately or together — still collapse to one alert. |

### Non-destructive reevaluation

`StudentReviewHidden`/`Rejected`/`Archived` and a no-show-reversing
`LessonOutcomeOverridden` never delete or silently mutate an existing
alert's resolution status — they flag `needs_reevaluation = true` via
`ReevaluateInstructorQualityAlertAction`, preserving full history for
a human (or the reconciliation command) to look at again. The reverse
direction of an outcome override — correcting a lesson *into*
`InstructorNoShow` — runs the normal detector instead, exactly as if
it had been finalized that way originally.

### Administrative review (`InstructorQualityAlertService`)

`startReview`, `resolve`, `dismiss`, `markDuplicate`, `assign` —
`resolve`/`dismiss` require a reason; every method is idempotent on
repeat (a second identical call is a no-op, silently discarding a
differing recommendation) and throws
`InvalidInstructorQualityAlertTransitionException` on a genuine
conflict. **This phase records recommendations only** —
`InstructorQualityAlertResolutionAction` (`NoAction`,
`MonitorInstructor`, `ContactInstructor`, `IssueWarning`,
`RequestQualityReview`, `ReferForSuspensionReview`, including the
dormant `ReferForSuspensionReview` value, which never itself touches
`InstructorStatus::Suspended` or anything else) is a note for a human
to act on later — nothing here ever suspends an instructor, hides a
profile, removes availability, changes compensation, or alters
marketplace ranking. Every decision is audited via `AuditTrailService`
under the `quality` log name.

### Authorization

`ViewAny:InstructorQualityAlert`, `View:InstructorQualityAlert`,
`Resolve:InstructorQualityAlert` — all manager-only (seeded via the
same `ReviewPermissionSeeder` used for every review-cluster
permission). **No instructor or student ever sees a quality alert in
this phase** — there is no public quality score and no self-service
visibility. `resolve` additionally denies the instructor the alert is
about, mirroring `ReviewReportPolicy`'s identical self-resolution
exclusion.

### Reconciliation (`reviews:reconcile-quality-alerts`)

A repair tool, not the primary update path — disabled-safe (returns
immediately when `quality_alerts_enabled` is off) and idempotent
(re-running produces no new alerts). Per spec, re-evaluates only
*recent published reviews and finalized lesson outcomes* — not
cancellations or report signals — cursored via `lazyById()` so the
full table is never loaded into memory, with per-record failure
isolation (`Log::warning`, one bad record never aborts the batch),
identical to `ReviewEligibilityService::expireDue()` /
`InstructorRatingAggregateService::rebuildAll()`. **Not scheduled.**

### Read projection (`InstructorQualityAlertAdminData`)

For a future admin UI — instructor reference/name, alert type/
severity/status, signal count, threshold snapshot, source reference, a
200-char review-content excerpt, triggered date, assignment/resolution
details. **Never**: student contact details, private feedback text,
payment information, instructor compensation, raw provider metadata,
or raw report explanations.

### Deferred

Instructor quality scores (numeric/public), warning enforcement,
instructor suspension (the enum value exists to record a
*recommendation*; no code path ever transitions
`InstructorStatus::Suspended`), notifications (all 5 events dispatch
with zero listeners), an admin quality dashboard (Phase 17O), and
marketplace ranking changes.

## Admin Review & Quality Assurance Dashboard (Phase 17O)

A read-only Filament admin page (`/admin/reports/reviews-quality`,
`Reports` nav group) surfacing Phase 17H–17N's data for operational
triage — moderation workload, review reports, instructor quality
alerts, and instructor rating health. **No new moderation, reporting,
rating, or alert records were created** — every number and row is a
live aggregation over `lesson_reviews`, `review_reports`,
`quality_alerts`, and `instructor_rating_aggregates`, and every action
delegates to the existing `ReviewModerationService`/
`ReviewReportService`/`InstructorQualityAlertService`.

### Read service (`AdminQualityDashboardService`)

The single, fully-tested read boundary — six methods, each returning
privacy-safe DTOs (`QualityDashboardSummaryData`,
`ModerationQueueRowData`, `ReportQueueRowData`,
`InstructorQualityAlertAdminData` (reused from Phase 17N — no
duplicate DTO was created for the alert queue), `InstructorRatingHealthRow`)
or a bounded array trend series, never a raw Eloquent model. Backed by
`AdminQualityDashboardRepositoryInterface` (new — read-only aggregation
queries against the four tables above) and the existing
`QualitySignalRepositoryInterface` (Phase 17N, reused as-is for
per-instructor no-show/cancellation counts on the rating-health rows).

### Summary metrics

Submitted/flagged/published/hidden/rejected review counts, pending/
under-review report counts, open/under-review alert counts,
high/critical-severity **active** alert counts (a workload metric —
resolved alerts of any severity don't count), instructors-with-
published-ratings count, platform eligible published review count,
platform average rating (summed across every `instructor_rating_aggregates`
row — `null`, never `0`, when nothing is eligible yet), and a
configurable-window (default 30 days) instructor no-show / instructor-
attributed-cancellation count. Private feedback and hidden/rejected/
archived reviews are structurally absent from every rating metric —
they're computed from `instructor_rating_aggregates`, which Phase 17K
already excludes them from.

### Review moderation queue

`ModerationQueueRowData::fromReview()` — review id, instructor,
**masked** student label (reusing Phase 17L's exact
`PublicReviewerIdentity::label()` — staff see no more of a student's
identity here than a public visitor would), review mode, rating,
submitted date, status, sanitization flags, report count. Filters:
status, instructor, rating, paid/demo lesson type (via the
eligibility relation), submitted date range. The Filament widget links
each row's instructor to their existing admin detail page
(`UserResource::getUrl('view', ...)`) rather than adding a new
instructor-detail route.

### Review-report queue

`ReportQueueRowData::fromReport()` — report id, review id, instructor,
reason, status, submitted date, current review status, report count
for that review. **No reporter identity field at all** — the spec
permits exposing it only to a separately-permissioned inspector, and
no queue use case needs it; the full record (with reporter id) is
still reachable through `ReviewReportService::adminProjection()` for
anyone who separately holds `View:ReviewReport`.

### Quality-alert queue

Reuses `InstructorQualityAlertAdminData` (Phase 17N) directly — no new
DTO. Filters: type, severity, status, assigned admin, instructor,
triggered date range. Row actions (Start Review, Assign, Resolve,
Dismiss, Mark Duplicate) call `InstructorQualityAlertServiceInterface`
exactly as an admin would from any other integration — the widget
itself never writes `quality_alerts.status`, and every action's
`->authorize()` closure calls the exact same `InstructorQualityAlertPolicy::resolve()`
check the service enforces internally (an instructor still cannot
resolve an alert about themself, even from this page). Domain
exceptions (`AuthorizationException`, `QualityAlertValidationException`,
`InvalidInstructorQualityAlertTransitionException`) are caught and
surfaced as a Filament notification, mirroring `BookingsTable`'s
existing `callService()` pattern.

### Low-rated / highly-rated instructors

New dashboard-only settings, deliberately distinct from the Phase 17N
alert threshold (`low_rating_threshold`, an integer per-review cutoff
that drives *detection* — reused only where its meaning is actually
identical, never blindly): `quality_dashboard_low_rating_threshold`
(2.5, float), `quality_dashboard_high_rating_threshold` (4.5, float),
`quality_dashboard_min_review_count` (3) — an instructor's *aggregate
average* must cross the float threshold **and** have at least this
many eligible reviews to appear in either list, so a single 1-star
review never misclassifies a new instructor. Each row: instructor,
average, eligible count, rating distribution, a lightweight "recent
rating trend" (average of the last 5 contributing reviews, from the
Phase 17K contribution ledger, minus the overall average — `null`,
never a fabricated `0`, when there's too little data to say anything:
fewer than 3 eligible reviews or fewer than 2 recent contributions),
open quality-alert count, no-show count, cancellation count. **No
numeric internal quality score is computed anywhere.**

### Trend data

Seven bounded daily series (published reviews, low-rated published
reviews, flagged reviews, review reports, quality alerts, instructor
no-shows, instructor-attributed cancellations), each a `Y-m-d => count`
array with every day present (zero-filled, never a sparse array) —
computed via `GROUP BY DATE(column)` database aggregation, never by
loading rows into PHP. `TrendDateRange::make()` always normalizes to
UTC and silently **clamps** (never throws) a custom range wider than
`$maxDays` (default 90) down to the most recent `$maxDays` days ending
at the requested end — a dashboard should always render something
useful rather than fail outright on an operator's overly-wide request.

### Filament page & authorization

`ReviewsQualityDashboard` (`Reports` nav group, sort 6) — a widget-only
page cloned structurally from the existing `BookingReports` page
(header widgets, no custom Blade content). Five new permissions
(`ViewQualityDashboard`, `ViewReviewMetrics`, `ViewReviewModerationQueue`,
`ViewReviewReports`, `ViewInstructorQualityAlerts`), seeded to
`manager` only via the same `ReviewPermissionSeeder` every other
review/quality permission uses — deliberately **separate** from the
granular `ViewAny:LessonReview`/`ViewAny:ReviewReport`/
`ViewAny:InstructorQualityAlert` permissions so a future finer-grained
role could hold a subset (e.g. the moderation queue without
instructor-quality-alert visibility — "review moderators may not
automatically receive access to sensitive instructor-quality records").
Every widget's `canView()` gates on its own specific permission, not
just the page-level one. `QualityDashboardAccess::userCan()` centralizes
the exact super-admin-bypass + `hasPermissionTo()` check
`CacheManagerPage` already established as this codebase's convention
for operational (non-CRUD-resource) admin pages. **No instructor or
student can ever reach this page** — there is no public or
instructor-facing quality dashboard in this phase.

### Privacy & performance

Every DTO is an explicit field allowlist — no `student.email`,
`student.phone`, payment/wallet/compensation column, raw attendance
provider metadata, raw report explanation, or internal audit payload
is ever selected, referenced, or returned by any method in this
phase. All queues are paginated (10/25/50, deterministic
`ORDER BY ... DESC, id DESC`), counts use database aggregation
(`COUNT`/`SUM`/`GROUP BY`) never full-collection loads, and every
`whereHas`/eager-load is scoped to avoid N+1 queries. No caching was
added — no existing per-record dashboard-caching convention exists in
this codebase (same conclusion Phase 17L reached for the public
profile page), and a dashboard read failure is isolated to the
dashboard: nothing here can affect review submission, moderation, or
quality-alert processing, since every read method is provably
mutation-free (`test_no_direct_review_or_alert_status_mutation_occurs`).

### Supported vs. deferred SRS metrics

Implemented (backed by reliable existing domain data): low/highly
rated instructors, review queue, flagged reviews, quality alerts,
instructor no-show patterns, cancellation patterns. **Deferred — no
authoritative data or calculation exists yet, so nothing is fabricated**:
student complaints as a distinct concept beyond review reports,
demo-conversion quality, and retention indicators. Each shows no
section at all rather than a fabricated zero.

## Instructor Quality Insights (Phase 17P)

A read-only page on the existing Account Portal instructor dashboard
(`/dashboard/instructor/quality-insights`, "Reviews & Quality" nav
item) letting an instructor see privacy-safe insights derived from
their own eligible review data. **No new aggregate, review
projection, or dashboard system was created** — the rating summary is
the unmodified Phase 17K aggregate and the recent-reviews list is the
unmodified Phase 17L public-review projection.

### Read service (`InstructorQualityInsightsService`)

Two methods: `insightsFor(User $instructor): InstructorQualityInsightsData`
and `recentReviewsFor(User $instructor, int $perPage = 10): LengthAwarePaginator<PublicInstructorReviewData>`.
Both accept only a `User` the caller already resolved — never a bare
id — so there is no parameter through which one instructor could
request another's data; the controller passes `auth()->user()`
exclusively. `insightsFor()` composes:

- `ratingSummary` — `InstructorRatingAggregateServiceInterface::summaryFor()`
  (Phase 17K), reused unmodified. Extended with one purely additive
  field, `dimensionCounts` (how many eligible reviews actually carried
  each dimension rating — the sample size behind each average, since a
  missing dimension rating is never counted as zero or folded into
  `reviewCount`). Only two construction sites exist codebase-wide
  (both inside `InstructorRatingAggregateService::summaryFor()`), so
  the extension carried no risk of breaking an unrelated caller.
- `topDimensions` / `improvementAreas` — deterministic dimension
  buckets, never AI-generated. A dimension qualifies only with at
  least `MIN_DIMENSION_SAMPLE = 3` contributing reviews; `topDimensions`
  takes dimensions averaging `>= 4.0`, `improvementAreas` takes
  dimensions averaging `< 3.0` — disjoint by construction, so no
  dimension can appear in both lists — each capped at 3 entries,
  sorted best/worst first. Below-threshold dimensions are silently
  omitted rather than shown as a fabricated "problem" from too little
  data. Improvement-area wording is neutral ("Students rated this area
  lower across N reviews") — no punitive or disciplinary language, no
  admin conclusion, no quality-alert reason.
- `feedbackTags` — a single neutral tag-frequency aggregation over the
  instructor's own eligible published public reviews (label + count
  only). `ReviewTag` has no sentiment/category field in its data
  model, so a positive-vs-improvement tag split is **not**
  authoritative and was deliberately not invented; "areas for
  improvement" is instead driven entirely from dimension averages
  (above). Computed by `LessonReviewRepository::tagCountsForInstructor()`
  via a narrow-column (`id`, `tags` only), cursored (`lazyById()`)
  scan with in-PHP counting — MySQL has no simple portable way to
  aggregate occurrences inside a JSON array column in this codebase's
  established query style, so this is a deliberate, bounded,
  constant-memory trade-off rather than a `JSON_TABLE` query.
  Private-feedback tags are excluded entirely — no existing policy
  grants an instructor visibility into private-feedback content, so
  this is documented as deferred rather than a newly invented rule.

`recentReviewsFor()` is a direct pass-through to
`PublicInstructorReviewServiceInterface::paginatedReviewsFor()` — the
exact same DTO, masking, pagination, and ordering as the public
profile page.

### Completion consistency & response time — deferred, not fabricated

Audited before writing any code: no authoritative completion-rate or
response-time calculation exists anywhere in this codebase, admin- or
instructor-facing. Per spec, neither was invented from booking status
or unrelated timestamps. **Both are entirely deferred SRS coverage** —
no field, no placeholder, no zero value; the page simply has no
section for either.

### Dashboard integration & authorization

`InstructorQualityInsightsController::index()` — a thin, role-gated
controller (`abort_unless(auth()->user()?->hasRole('instructor'), 403)`)
returning a page-shell Blade view that embeds one full-page Livewire
component, `frontend.instructor.quality-insights-overview`, following
the same page-shell + embedded-Livewire convention as every other
instructor dashboard sub-page (`InstructorAvailabilityController` is
the template). The component recomputes both DTOs fresh via `app(...)`
on every `render()` rather than storing them as public properties —
the same fresh-resolve convention Phase 17O's Filament widgets use, to
avoid Livewire's DTO-serialization/hydration fragility with nested
readonly objects. `AccountMenuService` gained one new `instructor`
audience nav entry ("Reviews & Quality") — `PortalResolver::frontendMenu()`
was not touched. A student, another instructor, or a guest hitting the
route is denied (403 or redirect-to-login); an admin's Phase 17O
dashboard is completely unaffected — the instructor route grants no
moderation, report-resolution, or alert-resolution permission, and the
instructor cannot edit, hide, reject, delete, or otherwise mutate a
review or the aggregate from this page (`insightsFor()`/`recentReviewsFor()`
are provably read-only — repeated calls never change a review's
`version`).

### Privacy & performance

Every DTO (`InstructorQualityInsightsData`, `DimensionInsightData`,
`FeedbackTagCountData`, plus the reused `InstructorRatingSummaryData`/
`PublicInstructorReviewData`) is an explicit field allowlist — no
student email/phone/profile-image/id, no private-feedback text, no
moderation reason, no review-report detail or reporter identity, no
internal quality-alert score or reason, and no financial/compensation
value is ever selected or returned. Reviewer identity reuses Phase
17L's exact masking (`PublicReviewerIdentity`) — an instructor sees no
more of a student's identity here than a public visitor would. Recent
reviews are paginated (never loaded fully into memory); tag counting
uses a narrow-column cursor rather than loading full rows; dimension
bucketing and the rating summary both read from the already-maintained
`instructor_rating_aggregates` row, never a live re-scan of
`lesson_reviews`. No new caching was added — no existing
instructor-dashboard caching convention exists in this codebase (the
same conclusion Phases 17L and 17O reached); a dashboard read failure
is isolated to this page and cannot affect review, lesson, booking, or
earnings workflows.

## Student Review Portal & Limited Editing (Phase 17R)

Replaces the student "coming soon" reviews placeholder at the existing
`/dashboard/reviews` route (existing `StudentReviewsController` +
`dashboard.reviews` nav entry reused unchanged — no new route was
needed) with `frontend.student.reviews-portal`, a full-page Livewire
component showing: open review opportunities (instructor public name,
lesson date, paid/demo type, public/private mode, expiry, submit
action), the student's own submitted reviews with statuses and edit
deadlines, past opportunities without actions
(expired/revoked/manual-review; Used windows simply appear as their
review), and plain-language status explanations. Everything is scoped
to `auth()->user()`; no booking payment detail, compensation, internal
lesson identifier, moderation metadata, reporter identity, or quality
alert is ever rendered.

### Submission integration

The portal form calls the exact Phase 17I
`StudentReviewServiceInterface::submit()` — no validation,
sanitization, eligibility, or moderation logic was recreated in
Livewire. Review mode still comes from the eligibility, the rating
scale from settings, tags from active configured `ReviewTag`s
applicable to that mode, and a successful submission atomically marks
the eligibility Used exactly as before.

### Edit window & editable statuses

`review_editing_enabled` (true) and `review_edit_window_hours` (24) —
the window starts at `submitted_at`, never publication/moderation/
page-load time. `ReviewEditPolicy` is the single editability
predicate (used by both the action's under-lock revalidation and the
portal's display): the student must own the review, both
`reviews_enabled` and `review_editing_enabled` must be on, the status
must be Submitted/Flagged/Published/Private (Hidden, Rejected, and
Archived are never editable), the window must be open, **any**
`ReviewReport` row blocks editing (pending/under-review are active
locks; a terminally resolved report stays blocked — no existing policy
permits reopening, and the student sees only the neutral "completed or
active moderation history" message), and the linked
eligibility/lesson must still structurally reference the same student.
Admins never edit through this path — they keep using
`ReviewModerationService`.

### Edit flow (`EditStudentReviewAction`)

One transaction: lock the review → revalidate editability → validate
against the review's OWN `settings_snapshot` (a later rating-scale
change never widens a historical review's bounds) → sanitize via the
same `ReviewContentSanitizer` → append the immutable pre-edit revision
→ apply the edit with exactly one version increment (status changes go
through the same `TransitionReviewStatusAction`) → **synchronously
reconcile the rating contribution while the review is provably not
Published** → audit (`student_review_edited`: prev/new status +
version, changed field *names*, flag categories — never raw text) →
dispatch `StudentReviewEdited` after commit.

The synchronous reconcile ordering is load-bearing: the Phase 17K
reconciler compares "should contribute" against "does contribute" and
no-ops when they agree — if removal waited for the after-commit
listener, an auto-republished edit could be seen as "already included"
and keep the stale pre-edit rating in the aggregate forever. Removing
under lock while un-published, then letting the Published event
listener re-add with the NEW values, guarantees the old contribution
is removed exactly once and the new one applies only after
republication. (The contribution row snapshots the values it applied,
so removal always subtracts what was actually added, regardless of
what the review row now says.)

### Re-moderation targets

`StudentReviewStatus::allowedTransitions()` gained the edit paths
(Flagged → Submitted, Published → Submitted/Flagged, Private →
Flagged): a clean public edit returns to Submitted and re-enters the
same automatic moderation (`ModerateReviewOnStudentReviewEdited`
listener → `ModerateSubmittedReviewAction`, which may auto-publish
under the current policy); a flagged public edit waits at Flagged for
a human; clean private feedback stays Private; flagged private
feedback goes to Flagged with its `review_mode` intact, so Phase 17J's
mode-derived approve target can only ever return it to Private, never
public. An edited Published review leaves public visibility
immediately — old content is never shown while the edit is pending.

### Revision history (`lesson_review_revisions`)

Append-only, one row per successful edit, written exclusively by
`EditStudentReviewAction`: previous overall + dimension ratings,
sanitized content (raw prohibited text never reached the review row,
so it structurally cannot reach a revision), tags, status,
sanitization metadata, moderation snapshot, the review's pre-edit
`version` (unique per review — a duplicate/replayed append is
structurally impossible), editor id, edited-at. No update or delete
path exists anywhere. The portal shows the owner only a safe summary
("Edited N times"); full revision content is reachable only by
audit/admin services and the owner through the model layer.

### Files

```
app/Reviews/
├── Actions/        EditStudentReviewAction (new)
├── Contracts/      StudentReviewEditingServiceInterface, LessonReviewRevisionRepositoryInterface (new)
├── DTOs/           EditStudentReviewData, ReviewEditability (new)
├── Events/         StudentReviewEdited (new)
├── Repositories/   LessonReviewRevisionRepository (new)
├── Services/       StudentReviewEditingService (new)
└── Support/        ReviewEditPolicy (new)

app/Models/LessonReviewRevision.php (new); LessonReview gains revisions()
app/Listeners/Reviews/ModerateReviewOnStudentReviewEdited.php,
                      ReconcileRatingContributionOnStudentReviewEdited.php (new,
                      registered in EventServiceProvider — event discovery is off)
app/Livewire/Frontend/Student/ReviewsPortal.php (new)
resources/views/livewire/frontend/student/reviews-portal.blade.php,
                                          partials/review-form.blade.php (new)
resources/views/student/reviews/index.blade.php (placeholder replaced)
database/migrations/2026_08_29_100000_create_lesson_review_revisions_table.php
database/settings/2026_08_29_100000_add_review_editing_settings.php
```

## Review & Quality Notifications (Phase 17S)

Wires the Reviews/Quality/Feedback domains into the codebase's one
fully-realized multi-channel notification pattern — `App\Booking`'s
`Illuminate\Notifications\Notification` classes + per-domain channel
resolver — rather than inventing a second notification system.

### Audit finding: the spec's assumed infrastructure only partly exists

The task described an existing "template repository and variable
renderer," per-user "notification preferences" enforced at send time,
and a general admin-recipient-by-permission helper. None of these
exist. What does exist: Laravel `Notification` classes per domain
group (`app/Notifications/{Booking,Admin,Wallet,...}/`), of which only
`Booking` has concrete, working subclasses — `Admin`, `Wallet`,
`Tutor`, `Payment`, `Support` are scaffolded base classes with **zero**
prior concrete notifications (`AdminAlertNotification` is used for the
first time in this phase). Channel selection is a plain PHP resolver
per domain (`App\Booking\Services\NotificationChannelResolver`, driven
by `BookingSettings` toggles), not a database-driven preference
system. `UserProfile::notification_preferences` (email/system/
marketing booleans) is stored but consulted by **no** existing send
path anywhere in the codebase — so Phase 17S does not invent
enforcement for it either; channel availability instead mirrors
Booking's own precedent exactly (admin-configured toggles, not a
personal per-notification opt-out). This mismatch between the spec's
assumption and the actual codebase is called out here rather than
silently pretending a template-management system exists.

### Reused unmodified

`App\Notifications\Admin\AdminAlertNotification` (first real usage),
`App\Notifications\Concerns\ConfiguresTransactionalEmail`,
`App\Notifications\Channels\{SmsChannel,WhatsAppChannel}`, the
`Booking`-pattern trait/resolver architecture, `PublicReviewerIdentity`
is deliberately **not** reused for notification content — payloads
never mention student identity at all rather than needing to mask it.

### New: `App\Notifications\Reviews\` / `App\Notifications\Quality\`

`ReviewNotification` (new base, mirrors `BookingNotification`, sender
key `review`) for student/instructor recipients; the three admin
notifications extend the existing `AdminAlertNotification` instead.
`RoutesReviewChannels` (mirrors `RoutesBookingChannels`) supplies
`via()`/`toWhatsApp()`/`toSms()`/`toDatabase()` to all nine classes;
`ReviewNotificationChannelResolver` (mirrors
`NotificationChannelResolver` exactly) reads three new
`ReviewSettings` toggles (`review_channel_email_enabled` = true,
`review_channel_whatsapp_enabled` = false, `review_channel_sms_enabled`
= false) — one shared policy for every recipient type, deliberately
not split into a "Review" vs. "Admin Alert" preference category. Two
new `MailSettings` fields (`review_from_name`/`review_from_email`)
back the new `review` sender key.

### Event → recipient matrix

| Event | Notification | Recipient(s) |
|---|---|---|
| `LessonReviewEligibilityOpened` | `ReviewRequestedNotification` | eligible student |
| `StudentReviewSubmitted` | `ReviewSubmittedNotification` | submitting student |
| `StudentReviewModerationRequired` **(new)** | `ReviewModerationRequiredNotification` | admins w/ `ViewReviewModerationQueue` |
| `StudentReviewPublished` | `ReviewPublishedStudentNotification` | review's student |
| `StudentReviewPublished` (PublicReview mode only) | `ReviewPublishedInstructorNotification` | review's instructor |
| `StudentReviewRejected` | `ReviewRejectedNotification` | review's student |
| `StudentReviewHidden` | `ReviewHiddenNotification` | review's student |
| `ReviewReported` | `ReviewReportedNotification` | admins w/ `ViewReviewReports` |
| `InstructorQualityAlertCreated` | `InstructorQualityAlertCreatedNotification` | admins w/ `ViewInstructorQualityAlerts` |

Deliberately **not** wired (per spec): `StudentReviewEdited`,
`InstructorStudentFeedbackSubmitted`, `ReviewReportDismissed`,
`ReviewReportMarkedDuplicate`, `InstructorQualityAlertResolved`,
`InstructorQualityAlertDismissed`. An edit still produces a
notification **indirectly** when its own re-moderation reaches
Published/Flagged/moderation-required — those are the existing
downstream events firing exactly as they would for any other
transition into that state, not a listener on `StudentReviewEdited`
itself.

### `StudentReviewModerationRequired` — a new, narrowly-dispatched event

No equivalent existed. A review needs a human at exactly two
authoritative decision points, and the event is dispatched from each
directly — never derived by a listener re-deciding the outcome from an
earlier event (which would race the automatic-moderation listener that
might still auto-publish the same review moments later):

1. `SubmitLessonReviewAction` — the instant a new submission's content
   trips the sanitizer and status becomes `Flagged`.
2. `EditStudentReviewAction` — the instant an edit's status becomes
   `Flagged`.
3. `ModerateSubmittedReviewAction`'s "held for manual moderation"
   branch — the only place that knows a `Submitted` review's automatic
   evaluation declined to publish it (pre-moderation, or the
   auto-publish override is off).

A flagged review never produces two notifications ("flagged" +
"needs moderation") — dispatching only from these three points, never
from a generic status-change observer, is what guarantees that.
Private flagged feedback still notifies moderators (they need to act)
but the notification never contains its content.

### Idempotency — `notification_dispatch_log`

Laravel's `notifications` table has no dedup key, and no existing
convention in this codebase guarantees "replayed event → one
notification" at the Illuminate-Notification layer (Booking relies
entirely on its events firing exactly once). Since every domain event
in the Reviews/Quality/Feedback stack already fires exactly once per
real transition, but a replayed queued listener or an admin reachable
through two permission paths must still never double-notify, a new
minimal table backs `NotificationIdempotencyGuard::once($key, $class,
$send)`: a unique-index INSERT claims the key; a
`UniqueConstraintViolationException` means "already claimed" and
`$send()` is skipped — the same lock-then-unique-constraint idiom this
codebase already uses everywhere else (eligibility opening, feedback
submission), applied to notification dispatch instead of a business
row. Keys: `review-request:{eligibility}:{version}:{recipient}`,
`review-submitted:{review}:{version}:{recipient}`,
`review-published:{review}:{version}:{recipient}`,
`review-rejected:{review}:{version}:{recipient}`,
`review-hidden:{review}:{version}:{recipient}`,
`review-moderation:{review}:{version}:{recipient}`,
`review-reported:{report}:{recipient}`,
`quality-alert-created:{alert}:{recipient}`. A legitimate later
version (a republish after an edit) is a different key and does
notify again.

### Admin recipient resolution — `AdminRecipientResolver`

Resolves active users holding one specific permission — the *exact*
permission each Phase 17O queue widget already gates its own
`canView()` on (`ViewReviewModerationQueue`, `ViewReviewReports`,
`ViewInstructorQualityAlerts`), so "who can act on this" and "who gets
notified about this" can never drift apart. `super_admin`s are always
unioned in (they bypass every permission check via `Gate::before()`,
so excluding them would silently under-notify the one role that can
always act) and deduplicated with anyone holding the permission
directly — a manager who is also flagged super_admin, or who reaches
the permission through two different roles, still receives exactly
one notification. Inactive users are excluded. Never resolves "every
administrator-like user."

### Privacy

Every notification's `toMail()`/`toDatabase()`/`plainText()` is an
explicit allowlist, mirroring the DTO-allowlist discipline used
everywhere else in this domain: no raw review text, no raw report
explanation, no reporter identity, no student email/phone, no
moderation notes, no internal quality score, no payment/compensation
data. The instructor-facing published notification never names the
student at all (simpler and stricter than masking). The
quality-alert notification names the instructor in full — a
staff-facing reference, the same identity staff already see on every
other Phase 17O queue row — but never the student, never raw
review/report text, never attendance-provider metadata or financial
data, and the instructor is never a recipient of an alert about
themself.

### Side-effect boundary

Every listener does exactly three things: resolve recipients, build a
notification instance from already-committed state, call `->notify()`
(or the idempotency guard around it). None changes review status,
opens/revokes eligibility, publishes/hides a review, resolves a
report, changes an alert, recalculates a rating, or touches a lesson/
booking/financial record. Every source event implements
`ShouldDispatchAfterCommit`, so the business transaction that produced
the state a notification describes is already committed before any
listener runs — a notification failure cannot roll it back.

### Files

```
app/Notifications/Reviews/
├── Concerns/RoutesReviewChannels.php
├── ReviewNotification.php (base)
├── ReviewRequestedNotification.php
├── ReviewSubmittedNotification.php
├── ReviewPublishedStudentNotification.php
├── ReviewPublishedInstructorNotification.php
├── ReviewRejectedNotification.php
├── ReviewHiddenNotification.php
├── ReviewModerationRequiredNotification.php (extends AdminAlertNotification)
└── ReviewReportedNotification.php (extends AdminAlertNotification)
app/Notifications/Quality/InstructorQualityAlertCreatedNotification.php (extends AdminAlertNotification)

app/Reviews/Events/StudentReviewModerationRequired.php (new)
app/Reviews/Support/ReviewNotificationChannelResolver.php (new)
app/Services/Notifications/NotificationIdempotencyGuard.php,
                            AdminRecipientResolver.php (new)
app/Models/NotificationDispatchLog.php (new)

app/Listeners/Reviews/Send{ReviewRequested,ReviewSubmitted,
    ReviewPublishedNotifications,ReviewRejected,ReviewHidden,
    ReviewModerationRequired,ReviewReported}Notification.php (new)
app/Listeners/Quality/SendInstructorQualityAlertCreatedNotification.php (new)

app/Reviews/Actions/{SubmitLessonReviewAction,EditStudentReviewAction,
    ModerateSubmittedReviewAction}.php (modified — one new event
    dispatch call each, at the exact authoritative point)
app/Reviews/Enums/StudentReviewStatus.php (unchanged — Phase 17R
    already added every transition this phase needed)

app/Settings/ReviewSettings.php (+3 channel toggles)
app/Settings/MailSettings.php (+review_from_name/review_from_email)
database/migrations/2026_08_30_100000_create_notification_dispatch_log_table.php
database/settings/2026_08_30_100000_add_review_mail_sender_settings.php,
                   2026_08_30_100100_add_review_notification_channel_settings.php

app/Providers/EventServiceProvider.php (modified — 8 new listener
    registrations across 5 event keys, 3 of them brand new keys)
```

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
Phase 17R adds `StudentReviewEdited` (`ShouldDispatchAfterCommit`,
exactly once per successful edit) with exactly two listeners — the
same moderation pipeline (`ModerateReviewOnStudentReviewEdited`) and
the same aggregate reconciler
(`ReconcileRatingContributionOnStudentReviewEdited`) — and nothing
else.

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

app/Quality/  (Phase 17N — a new domain, parallel to Reviews/Lessons/Booking)
├── Actions/        RecordQualityAlertSignalAction, TransitionInstructorQualityAlertStatusAction,
│                   ReevaluateInstructorQualityAlertAction, ReconcileInstructorQualityAlertsAction,
│                   DetectLowRatingQualityRiskAction, DetectInstructorNoShowQualityRiskAction,
│                   DetectInstructorCancellationQualityRiskAction, DetectSeriousReviewReportQualityRiskAction
├── Contracts/      InstructorQualityAlertRepositoryInterface, InstructorQualityAlertServiceInterface,
│                   QualitySignalRepositoryInterface
├── DTOs/           QualitySignalData, InstructorQualityAlertAdminData
├── Enums/          InstructorQualityAlertType, InstructorQualityAlertSeverity,
│                   InstructorQualityAlertStatus, InstructorQualityAlertResolutionAction,
│                   QualityAlertSourceType
├── Events/         InstructorQualityAlertCreated, InstructorQualityAlertEscalated,
│                   InstructorQualityAlertReviewStarted, InstructorQualityAlertResolved,
│                   InstructorQualityAlertDismissed
├── Exceptions/     InvalidInstructorQualityAlertTransitionException, QualityAlertValidationException
├── Repositories/   InstructorQualityAlertRepository, QualitySignalRepository
├── Services/       InstructorQualityAlertService
└── Support/        QualityAlertFingerprint, QualityAlertSeverityPolicy,
                    QualityAlertThresholdSnapshot, InstructorCancellationAttribution

app/Models/InstructorQualityAlert.php  (table: quality_alerts)
app/Policies/InstructorQualityAlertPolicy.php
app/Listeners/Quality/  (thin triggers — no detection logic)
app/Console/Commands/ReconcileInstructorQualityAlerts.php  (reviews:reconcile-quality-alerts)
app/Providers/QualityServiceProvider.php (bootstrap/providers.php)

Phase 17O adds to the same App\Quality domain (read-only additions only):
├── Contracts/      AdminQualityDashboardRepositoryInterface, AdminQualityDashboardServiceInterface
├── DTOs/           QualityDashboardSummaryData, InstructorRatingHealthRow, ModerationQueueRowData,
│                   ReportQueueRowData, ModerationQueueFilters, ReportQueueFilters, AlertQueueFilters,
│                   TrendDateRange
├── Repositories/   AdminQualityDashboardRepository
├── Services/       AdminQualityDashboardService
└── Support/        QualityDashboardAccess

app/Filament/Pages/ReviewsQualityDashboard.php  (/admin/reports/reviews-quality)
app/Filament/Widgets/Quality/
├── QualityStatsWidget.php              (summary — StatsOverviewWidget)
├── ModerationQueueWidget.php           (TableWidget, filtered)
├── ReportQueueWidget.php               (TableWidget, filtered)
├── AlertQueueWidget.php                (TableWidget, filtered, delegated row actions)
├── LowRatedInstructorsWidget.php       (TableWidget)
└── HighlyRatedInstructorsWidget.php    (TableWidget)
resources/views/filament/pages/reviews-quality-dashboard.blade.php

Phase 17P adds to the existing App\Reviews domain (no new domain):
├── Contracts/      InstructorQualityInsightsServiceInterface (new)
├── DTOs/           InstructorQualityInsightsData, DimensionInsightData,
│                   FeedbackTagCountData (new); InstructorRatingSummaryData
│                   gains one additive field, dimensionCounts (modified)
└── Services/       InstructorQualityInsightsService (new)

LessonReviewRepositoryInterface/LessonReviewRepository gain one method,
tagCountsForInstructor() (modified, not a new repository).

app/Http/Controllers/Instructor/InstructorQualityInsightsController.php
app/Livewire/Frontend/Instructor/QualityInsightsOverview.php
resources/views/instructor/quality-insights/index.blade.php
resources/views/livewire/frontend/instructor/quality-insights-overview.blade.php
app/Services/Account/AccountMenuService.php  (one new instructor nav entry)
routes/web.php  (dashboard.instructor.quality-insights, inside the
                 existing dashboard prefix/middleware group)
```

## Deployment runbook

1. `php artisan migrate --force` — creates `lesson_review_eligibilities`,
   `review_tags`, `lesson_reviews` (+ its Phase 17J moderation columns),
   `instructor_rating_aggregates`, `review_rating_contributions`,
   `review_reports`, `quality_alerts`, and seeds the `reviews.*`
   settings defaults (including Phase 17L's
   `public_review_identity_mode`, Phase 17M's
   `review_reporting_enabled`, Phase 17N's 9 quality-alert threshold
   settings — `quality_alerts_enabled` defaults **false** — and Phase
   17O's 3 dashboard-only rating-classification thresholds).
2. `php artisan db:seed --class=ReviewPermissionSeeder --force` —
   mandatory: without it only `super_admin` can view/moderate
   eligibility/review records, report a review, resolve a report,
   view/resolve a quality alert, or open the quality dashboard at all.
3. `php artisan db:seed --class=ReviewTagSeeder --force` — idempotent
   default tag catalog; without it no tags exist to select.
4. Queue worker (`notifications` queue) — the outcome listeners, the
   automatic-moderation listener, the five rating-reconciliation
   listeners, and the eight Phase 17N quality-alert listeners are all
   queued. The five Phase 17M report events and five Phase 17N
   quality-alert events dispatch after commit but currently have
   **no** queued notification listeners at all (reserved for a future
   phase). Phase 17O adds no new events or listeners — it is
   read-only.
5. Scheduler cron — gates `reviews:expire-eligibility` (hourly).
   `reviews:rebuild-aggregates` and `reviews:reconcile-quality-alerts`
   are **not** scheduled — run either manually only after suspected
   drift or a direct data/settings correction.
6. Phase 17P adds no migration, no permission, and no new listener or
   queued job — it is a read-only page reusing existing settings,
   aggregates, and permissions. `npm run build` picks up no new
   frontend assets beyond the existing Blade/Livewire/Vite pipeline.
7. Phase 17R: `php artisan migrate --force` also creates
   `lesson_review_revisions` and seeds the two editing settings
   (`review_editing_enabled` = true, `review_edit_window_hours` = 24).
   The two new `StudentReviewEdited` listeners run on the same
   `notifications` queue as every other review listener — no new
   worker. No new permission: editing is relationship-based (the
   review's own student), and staff continue using the existing
   moderation permissions.
8. Phase 17S: `php artisan migrate --force` also creates
   `notification_dispatch_log` and seeds the three channel-toggle
   settings (`review_channel_email_enabled` = true, whatsapp/sms =
   false) and the two `mail.review_from_*` sender fields. No new
   permission — admin recipients are resolved from the existing
   `ViewReviewModerationQueue`/`ViewReviewReports`/
   `ViewInstructorQualityAlerts` permissions `ReviewPermissionSeeder`
   already seeds. The 8 new listeners run on the same `notifications`
   queue as every other review/quality listener. No new frontend
   asset — notification content is server-rendered PHP, same as every
   other domain's notifications.
9. Phase 17U.2: `php artisan migrate --force` also drops the
   `features.reviews_enabled` decoy setting and restricts the
   `booking_guests`/`booking_meetings` → `bookings` foreign keys (see
   `docs/booking.md`). `LessonPermissionSeeder`,
   `ReviewPermissionSeeder`, and `FeedbackPermissionSeeder` now run
   automatically as part of `php artisan db:seed --force` (no manual
   `--class=X` step needed for these three going forward) —
   `ReviewPermissionSeeder` additionally seeds
   `settings.reviews_quality.view`/`.update` and the four `ReviewTag`
   permissions. No new queue worker or scheduler entry. `npm run
   build` picks up no new frontend assets (server-rendered Filament
   pages only).

## Review Administration, Runtime Configuration & Operational Workflow Remediation (Phase 17U.2)

Closes Phase 17T Findings S-1, S-4, and S-5, and the Phase 17U.1
`booking_guests`/`booking_meetings` residual (see `docs/booking.md`).

- **One canonical switch.** `reviews.reviews_enabled`
  (`ReviewSettings::$reviews_enabled`) is the sole reviews on/off
  switch platform-wide. The Finding S-1 decoy —
  `features.reviews_enabled`, a same-named `FeatureSettings` property
  never read by anything — is retired
  (`database/settings/2026_09_05_100100_remove_decoy_features_reviews_enabled_setting.php`)
  and removed from the Platform Foundation "Feature Flags" form.
  Disabling the canonical switch now blocks, immediately: new
  eligibility windows (`OpenLessonReviewEligibilityAction`, unchanged),
  a genuinely new submission even against an eligibility window opened
  before the switch flipped (`SubmitLessonReviewAction`, new check —
  an idempotent retry of an already-used window is unaffected), edits
  (`ReviewEditPolicy`, unchanged), new reports
  (`SubmitReviewReportAction`, new check alongside the existing
  `review_reporting_enabled`), and the "ready to review" notification
  (`SendReviewRequestedNotification`, unchanged). It never touches
  historical review visibility (`PublicInstructorReviewService`),
  moderation queue access, aggregates, or audit history.
- **Reviews & Quality Settings page**
  (`app/Filament/Pages/Settings/ReviewQualitySettingsPage.php`,
  `/admin/settings/reviews-quality`) is the sole runtime-configuration
  surface for `ReviewSettings` — General/Rating/Moderation &
  Privacy/Quality Alerts/Dashboard/Notification Routing sections,
  covering every Version 1 setting and nothing else (no instructor-
  response, AI/sentiment, marketplace-ranking, Learning-Plan-linkage,
  or notification-template field — none of those settings exist).
  Business-rule validation (min<max, threshold-within-scale,
  counts/windows ≥ 1, enum validity) runs before any write, so a
  rejected save persists nothing, even when only one of many changed
  fields is invalid. A non-empty reason is required specifically when
  `reviews_enabled` or `moderation_model` changes value; every save is
  audited via `AuditTrailService` (admin id, changed field diffs,
  reason when given) through the existing `LogsSettingsUpdates`
  pattern. Two distinct permissions —
  `settings.reviews_quality.view`/`.update` — gate the page; `save()`
  re-checks `.update` at execution time, not just via a hidden button.
  Enabling WhatsApp/SMS still saves (the channels are safe no-op
  stubs — `SmsChannel`/`WhatsAppChannel` log-and-skip) but surfaces a
  warning notification that no gateway is configured. No "at least one
  report reason enabled" rule exists: `ReviewReportReason` is a fixed
  enum with no per-reason enable/disable setting in Version 1, so
  there is nothing to validate there.
- **Review tag administration**
  (`app/Filament/Resources/ReviewTags/`, under **Reports → Review
  Tags**) — create/edit/activate/deactivate/reorder via `is_active`
  and `sort_order` only. `ReviewTag` has no `deleted_at` column at
  all, so there is no delete/force-delete action anywhere in the
  resource; a deactivated tag simply stops being offered to new
  submissions (`SubmitLessonReviewAction::resolveTags()` already
  scopes on `->active()`) while every already-submitted review's own
  `tags` snapshot is untouched. No positive/improvement classification
  is invented — `ReviewTag` has no sentiment field, matching the same
  discipline established in Phase 17P/17Q.
- **Moderation and report-resolution mutation actions** — the
  previously read-only Phase 17O `ModerationQueueWidget` and
  `ReportQueueWidget` now carry real row actions
  (approve/reject/hide/restore/archive; start-review/uphold/dismiss/
  mark-duplicate/mark-remaining-duplicate), every one delegating
  exclusively to `ReviewModerationService`/`ReviewReportService` —
  the widgets never write a `status` column themselves. Action
  visibility per row is a UX convenience based on current status; the
  service's own state-machine guard
  (`TransitionReviewStatusAction`/`TransitionReviewReportStatusAction`)
  is the authoritative check, so a stale click still fails safely.
  `LessonReviewPolicy::moderate()`/`hide()` gained the same
  instructor-self-exclusion `ReviewReportPolicy::resolve()` already
  had — an instructor can never moderate/hide a review about their
  own teaching, even if hypothetically granted the staff permission
  directly, mirroring the existing report-resolution precedent. A
  super-admin bypass (`Gate::before`) affects only permission checks,
  never the underlying transition guard — an illegal transition still
  throws `InvalidReviewTransitionException` regardless of who calls
  it.
- **Production seeding.** `LessonPermissionSeeder`,
  `ReviewPermissionSeeder` (now also seeding the settings/tags
  permissions above), and `FeedbackPermissionSeeder` are wired into
  `database/seeders/DatabaseSeeder.php` — previously only reachable
  via a manual `db:seed --class=X` or from tests. All three (like
  every permission seeder in this codebase) use
  `Permission::firstOrCreate` + `PermissionRegistrar::forgetCachedPermissions()`,
  so running the full seeder twice is a safe no-op.
- **`booking_guests`/`booking_meetings`** — the one deliberately-open
  residual from Phase 17U.1's booking-history FK remediation is
  closed; see `docs/booking.md`'s "Deletion policy" section.

See `tests/Feature/Settings/ReviewQualitySettingsPageTest.php`,
`tests/Feature/Filament/ReviewTagResourceTest.php`,
`tests/Feature/Filament/ReviewModerationWidgetActionsTest.php`,
`tests/Feature/Filament/ReviewReportWidgetActionsTest.php`,
`tests/Feature/Reviews/ReviewSettingsCanonicalizationTest.php`,
`tests/Feature/DatabaseSeederPermissionWiringTest.php`, and
`tests/Feature/Booking/BookingGuestMeetingProtectionTest.php`.

## Deferred (do not build yet)

Instructor responses/visibility toggles (Phase 17S explicitly does not
notify on `StudentReviewEdited`, does not create a response
notification, and no response template/listener/notification class
exists anywhere), report-dismissal/duplicate-marked and quality-alert-
resolved/dismissed notifications, review deletion (Phase 17R
implemented limited *editing*; physical deletion remains impossible
for every role), helpfulness voting, review translation, mobile push,
a new notification-center UI, a new template-management UI,
AI personalization or sentiment analysis in any notification, public/numeric instructor
quality scores, warning enforcement, instructor suspension (the
resolution-action *value* exists; no code transitions
`InstructorStatus::Suspended`), marketplace ranking, homework,
learning-plan progress, student-complaint/demo-conversion/retention
metrics (no authoritative data source exists yet — deliberately not
fabricated), and all frontend UI outside the instructor profile page
and the Phase 17P instructor quality-insights page. Also still
deferred as of Phase 17P: instructor-to-student feedback, Learning
Plan feedback linkage, instructor responses to reviews, response
moderation, AI-generated improvement suggestions, completion
consistency and response-time insights (no authoritative calculation
exists anywhere in the codebase — not derived from booking status or
unrelated timestamps), and private-feedback tag visibility to the
instructor (no existing policy grants it).

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
no notification table exists at the time this test was written; no
financial/lesson record changes; and a Phase 17J–17L regression check
(flag → approve → publish → aggregate → public page all still work).

`tests/Feature/Reviews/InstructorQualityAlertTest.php` (Phase 17N) —
a published review rated at/below threshold creates `SingleLowRating`;
above-threshold and private-feedback ratings create nothing;
submitted/hidden reviews never create a *new* alert at the transition
moment; 3 low published reviews cross the default repeated-count
threshold into exactly one `RepeatedLowRatings` alert with the correct
signal count; replayed/duplicate detection calls and two "concurrent"
copies of the same review both still produce exactly one alert; hiding
a review with an existing alert preserves the alert row and flags
`needs_reevaluation` without touching its status; `InstructorNoShow`
creates a signal while `StudentNoShow`/`TechnicalIssue` never do;
2 no-shows cross the repeated threshold into one alert; an outcome
override both directions (no-show → corrected away flags
reevaluation; non-no-show → corrected into a no-show creates the
signal) behave correctly; Host-attributed cancellations count while
Attendee/System cancellations are excluded, and 4 repeated
Host-cancellations still produce exactly one alert; an upheld serious
report (Abusive Language) creates `SeriousReviewReport`, a dismissed
or merely-duplicate-marked report creates nothing, and two separately
upheld reports against the *same* review still collapse to one alert;
the feature-disabled setting suppresses every detector; a
`threshold_snapshot` survives a later settings change unchanged; an
unauthorized user and the instructor the alert is about are both
denied resolution; a missing resolution reason is rejected; every
resolution is audited under the `quality` log name; resolving an alert
(including `ReferForSuspensionReview`) never changes
`UserProfile::instructor_status`; the admin DTO's field list contains
no student-contact/financial keys; `reviews:reconcile-quality-alerts`
both creates a missing alert and is idempotent on a second run; and no
notification/wallet/earning record is ever touched by alert creation
or resolution.

`tests/Feature/Reviews/AdminQualityDashboardTest.php` (Phase 17O) —
manager access succeeds while student/instructor/unpermissioned-
manager access is denied (both via HTTP and `ReviewsQualityDashboard::canAccess()`
directly); submitted/flagged review counts; private feedback and a
later-hidden review are both excluded from platform rating metrics
(`null`, never `0`); pending/under-review report counts; open alert
count; high/critical severity counts (scoped to active alerts only);
low-rated and highly-rated instructor lists both use the live
aggregate average; the minimum-review-count threshold excludes an
under-sampled instructor; a zero-review instructor is excluded
entirely; `InstructorNoShow` is the only outcome counted as a no-show
(`StudentNoShow`/`TechnicalIssue` excluded); `Host`-attributed
cancellations count while `Attendee`/`System` cancellations are
excluded; an outcome override is reflected in the very next read; all
three queue filter parameters (instructor, reason, instructor) narrow
results correctly; two consecutive paginated calls return identical
ordering; `TrendDateRange::make()` normalizes to UTC and clamps
(never throws on) an oversized custom range; DTOs carry no student-
contact or financial/compensation field names; a real Livewire
`callTableAction('resolve', ...)` against `AlertQueueWidget` provably
transitions the alert through `InstructorQualityAlertServiceInterface`
(not a direct status write); every dashboard read method called back
to back never changes a review's or alert's `version`; dashboard reads
create no wallet/earning records; and a Phase 17H–17N regression check
(flag → approve → publish → public page all still work).

`tests/Feature/Reviews/InstructorQualityInsightsTest.php` (Phase 17P)
— an instructor sees their own insights; the service never leaks one
instructor's data when called for another; a student and a guest are
both denied the route; the displayed average and eligible count match
`InstructorRatingAggregateServiceInterface::summaryFor()` exactly
(never recalculated); private feedback, hidden, rejected, and archived
reviews are all excluded from the rating summary; rating distribution
and dimension averages/counts are correct; a missing dimension rating
is never counted as zero; punctuality uses the review-dimension
aggregate only; feedback tags aggregate correctly without any student
identity field; a dimension below the minimum sample size never
appears in either highlight or improvement list; the insights DTO
carries no AI-summary/recommendation/coaching-advice field; recent
reviews are paginated and ordered deterministically newest-first;
reviewer identity stays masked; a private student email never reaches
the page; private review text, moderation reasons, and quality-alert/
risk-scoring language are all absent from the rendered page;
repeated read calls never mutate a review's `version` and the service
interface exposes no mutating method; no financial value is exposed;
the DTO carries no completion-consistency or response-time field
(confirming neither was fabricated); the existing public profile page
and the Phase 17O admin dashboard both remain unaffected; and a Phase
17H–17O regression check (flag → approve → publish → aggregate all
still work).

`tests/Feature/Reviews/StudentReviewPortalTest.php` (Phase 17R) — the
placeholder page is replaced; a student sees only their own open
eligibility; portal submission goes through the Phase 17I service
(public and private-demo modes); an expired or Used window shows no
submit action; a student sees their own reviews and never another
student's (including a foreign-review edit attempt 404ing through the
ownership-scoped lookup).

`tests/Feature/Reviews/StudentReviewEditingTest.php` (Phase 17R) — all
four editable statuses (Submitted/Flagged/Published/Private) accept an
edit while Hidden/Rejected/Archived reject it; the window expires from
`submitted_at` and the `review_editing_enabled` switch blocks
everything; the instructor and another student are both denied; an
edited published review disappears publicly while re-moderation is
pending; a clean public edit auto-republishes under the existing
policy while a flagged edit waits at Flagged; clean private edits stay
Private and a flagged private edit approved by an admin returns to
Private, never public; the pre-edit content/rating/status land in an
append-only revision that only ever contains sanitized text; raw
unsafe edit text never reaches the audit log; rating validation uses
the review's stored snapshot (a later `rating_max` change doesn't
widen it); an under-review report and a terminally dismissed report
both block editing; the old published rating leaves the aggregate
exactly once, the new one contributes only after republication, and
replayed reconcile calls converge; sequential edits produce one
revision per version with a same-version duplicate append structurally
impossible; a failed edit leaves the review and history byte-for-byte
unchanged; nothing is deleted, notified, or written to Learning Plans
or quality alerts; and a Phase 17H–17Q regression check.

`tests/Feature/Reviews/ReviewQualityNotificationTest.php` (Phase 17S)
— 35 scenarios: one review-request notification per eligibility
opening (expired/revoked eligibility and a replayed event both send
nothing/nothing-extra); submission confirmation; a published public
review notifies both its student and instructor, private feedback
never notifies an instructor; rejected/hidden notify only the
review's own student; a pre-moderation-held Submitted review and a
Flagged review (from submission or an edit) each notify moderators
exactly once, an auto-published clean review notifies no moderator;
a report notifies report-authorized admins, a quality alert notifies
alert-authorized admins (never the instructor it concerns);
unauthorized and inactive admins receive nothing, a manager reachable
through overlapping permissions/roles receives exactly one
notification; a replayed event and two calls with the same
idempotency key both produce exactly one send; a legitimate new
version (republish after an edit) does notify again; channel
selection follows the settings toggle, retry/tries/backoff match the
existing transactional-email configuration; the in-app payload's
action URL resolves to the correct named route; raw review text,
raw report explanation, student contact details, and reporter
identity are all absent from every payload; private instructor
feedback (Phase 17Q) sends no student notification and no
review-response class exists anywhere; every source event still
implements `ShouldDispatchAfterCommit` and review/report status is
unchanged by the act of notifying; no financial/aggregate record
changes; and a Phase 17H–17R regression check. Six pre-existing
"no notification is sent" tests from Phases 17H/17I/17J/17K/17M/17R
were updated in place to assert the specific, now-intentional
notification each exercised flow legitimately triggers, while still
confirming no *other* unrelated side effect occurs — matching each
test's original isolation intent, not a relaxation of it.

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
