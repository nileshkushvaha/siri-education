# Lesson Lifecycle

Phase 13 foundation: the layer that starts where a confirmed booking
ends — tracking scheduled → live → attendance → completion / no-show /
dispute / cancellation for 1-to-1 lessons. The lesson **extends** the
booking lifecycle after confirmation; it never replaces the booking,
and it never stores payment data (price, provider ids, wallet details
stay on the booking).

## Data model

`lessons` — one row per booking (`booking_id` unique, UUID PK):

- Participants: `student_id` (booking attendee), `instructor_id`
  (booking host) — bigint FKs to `users`.
- Academic snapshot from booking meta (Phase 12.5): `subject_id`,
  `subject_topic_id` (UUID FKs, resolved from `meta.topic_id` /
  `meta.subject` slug); `academic_level_id` is filled only when
  exactly one active academic level covers the booking's grade int —
  overlapping/country-specific levels are ambiguous and leave the FK
  null. The raw grade in `lessons.metadata.grade` is always the
  source of truth.
- Schedule: `starts_at` / `ends_at` (UTC), `timezone` (display only).
- `status` (`LessonStatus`): `scheduled`, `live`, `completed`,
  `student_no_show`, `instructor_no_show`, `both_no_show`,
  `cancelled`, `disputed`.
- Attendance per party: `{student,instructor}_attendance_status`
  (`pending` / `attended` / `no_show`) + `*_attended_at`.
- Completion: `completed_at`, `completed_by` (null when automatic),
  `auto_completed_at`, `completion_notes`, `dispute_reason`.
- `metadata` — sanitized whitelist only (`booking_reference`,
  `booking_type`, `subject`, `topic`, `grade`,
  `cancellation_reason`). Soft deletes.

## State machine

`LessonStatus::canTransitionTo()` is the single source of truth;
`TransitionLessonAction` guards every write.

```
Scheduled ──▶ Live ──▶ Completed ◀──▶ Disputed ──▶ Cancelled
    │           │──▶ StudentNoShow / InstructorNoShow / BothNoShow ──▶ Disputed
    └──▶ (any outcome directly — Live is optional)
```

- `Completed` and the no-show outcomes may still be disputed; a
  dispute resolves to `Completed` or `Cancelled` (admin).
- `Cancelled` is the only fully terminal state.

## Architecture

```
app/Lessons/
├── Actions/        CreateLessonFromBookingAction, TransitionLessonAction
├── Contracts/      LessonLifecycleServiceInterface, LessonRepositoryInterface
├── Enums/          LessonStatus, LessonAttendanceStatus
├── Exceptions/     LessonException, InvalidLessonTransitionException
├── Repositories/   LessonRepository
└── Services/       LessonLifecycleService

app/Models/Lesson.php · app/Policies/LessonPolicy.php
app/Providers/LessonServiceProvider.php (bootstrap/providers.php)
```

`LessonLifecycleService` is the **only** place lesson status or
attendance changes — controllers, Livewire, Filament, listeners, and
commands all call it. Audit flows through `AuditTrailService`
(log name `lessons`: `lesson_created`, `lesson_live`,
`lesson_attendance_marked`, `lesson_completed`,
`lesson_auto_completed`, `lesson_no_show`, `lesson_disputed`,
`lesson_cancelled`). `NotificationMapper` raises admin notifications
for exactly two of these: `lesson_no_show` (warning) and
`lesson_disputed` (danger). Completion stays deliberately silent —
the booking sync already raises "Booking Completed" for the same
event, and mapping both would double-notify. Participant-facing
lesson notifications remain deferred.

## Triggers & booking sync

- **Creation**: `CreateLessonOnBookingConfirmed` (queued,
  `notifications` queue) — the only automatic trigger, next to the
  meeting listener on `BookingConfirmed`. The service re-checks
  eligibility (confirmed + real attendee & host + concrete times +
  payment `NotRequired`/`Paid`) and idempotency, so the listener is a
  thin trigger. Pending/unpaid/cancelled/expired/guest bookings never
  grow a lesson.
- **Lesson → booking**: completing or no-show-finalizing a lesson
  pushes the outcome onto a still-confirmed booking through
  `BookingServiceInterface::complete()/markNoShow()`, so booking
  events/notifications/audit fire exactly like the admin's manual
  actions. Already-finalized bookings are left alone.
- **Booking → lesson**: `SyncLessonOnBookingCancelled` cancels the
  open lesson; `SyncLessonOnBookingCompleted` completes it, or — for a
  booking-level no-show with no recorded attendance — marks the
  student no-show (booking no-show has always meant the attendee in
  this 1-to-1 flow) and finalizes. Both directions no-op when the
  other side is already finalized — no loops.

## Settings (`LessonSettings`, Spatie group `lessons`)

- `auto_complete_enabled` (true) — kill switch for the sweep.
- `auto_complete_grace_minutes` (1440) — minutes after `ends_at`
  before an open lesson auto-finalizes.
- `no_show_grace_minutes` (15) — minutes after `starts_at` before a
  no-show may be recorded (admin/system `override: true` bypasses).
- `require_instructor_completion` / `require_student_attendance`
  (false) — when on, completion (manual and auto) requires that
  party's attendance to be `attended`; admin override bypasses, and
  the sweep leaves non-compliant lessons open for a manual decision.

## Completion rules

`complete()` is **idempotent** (re-completing is a no-op). Without
`override: true` the lesson must have ended and any required
attendance confirmations must be recorded; the admin panel and the
booking-driven sync always pass the override. Manual completion counts
unflagged parties as attended; auto-completion leaves attendance as
recorded (it asserts "nobody reported a problem", not verified
attendance).

## Auto-completion

`lessons:auto-complete` (scheduled every 15 min, routes/console.php)
finalizes open lessons whose `ends_at` passed
`auto_complete_grace_minutes`: recorded no-shows become the matching
no-show outcome; lessons missing a required attendance confirmation
stay open; everything else auto-completes with `auto_completed_at`
set. Idempotent; no payment/wallet/payout changes.

## Admin panel

**Lessons** (navigation group Booking) — list-only by design; no
Create/Edit pages (lessons are engine-created). Row actions delegate
to `LessonLifecycleServiceInterface` (mark live, mark attendance,
complete, finalize no-show, dispute, cancel), each policy-authorized,
visibility-guarded by `canTransitionTo`, and try/catch-wrapped into
Filament notifications. Navigation badge counts disputed lessons.

## Authorization

`LessonPolicy`: participants view their own lessons; the instructor
may mark attendance and complete their own lessons; students may
dispute; staff act through Shield-style permissions
(`ViewAny:Lesson`, `MarkAttendance:Lesson`, `Complete:Lesson`,
`Cancel:Lesson`, `Dispute:Lesson`, …). `create` is always false —
lessons are engine-created.

## Deployment runbook

1. `php artisan migrate --force` — creates `lessons` and seeds the
   `lessons.*` settings defaults (settings migration).
2. `php artisan db:seed --class=LessonPermissionSeeder --force` —
   **mandatory**: deny-by-default, without it only `super_admin` sees
   the Lessons admin.
3. Queue worker (`notifications` queue) — lesson creation/sync
   listeners are queued.
4. Scheduler cron — gates `lessons:auto-complete` (every 15 min).

## Attendance & Lesson Outcome

Evidence-grade attendance and an authoritative finalized outcome,
layered on top of (never replacing) the Phase 13 lifecycle.

### Data model

- `lesson_attendance_records` — one per lesson (unique `lesson_id`);
  merged per-party aggregates (first joined / last left / attended
  seconds / join count), latest `source`, `provider_reference`,
  sanitized `metadata`, `technical_issue_reported_at`, `finalized_at`.
- `lesson_attendance_events` — append-only evidence log; unique
  `(lesson_id, fingerprint)` (sha256 of the provider event id, or the
  normalized evidence tuple) is the idempotency guarantee. Aggregates
  are recomputed from this log as an interval **union**, so replays,
  out-of-order arrival, and overlapping sessions merge identically.
- `lessons` outcome columns — `outcome` (`LessonOutcome`: pending /
  completed / student_no_show / instructor_no_show / both_absent /
  technical_issue / cancelled), `outcome_reason_code`, `outcome_notes`
  (sanitized), `outcome_finalized_at/by/by_type`,
  `attendance_record_id`, `outcome_version` (concurrency lock value).
  Pre-17A finalized lessons were backfilled
  (`outcome_reason_code = backfill_phase_17a`).

### Services & actions

`LessonAttendanceServiceInterface` (record / finalize evidence) and
`LessonOutcomeServiceInterface` (determine / finalize / override) are
the only entry points. Under them: `RecordAttendanceAction`,
`FinalizeAttendanceAction`, `DetermineLessonOutcomeAction`,
`FinalizeLessonOutcomeAction`, `OverrideLessonOutcomeAction`.

- **`FinalizeLessonOutcomeAction` is the single writer of the outcome**
  — the outcome service *and* every legacy lifecycle finalization
  (manual complete, no-show, cancel, auto-complete sweep) funnel
  through it, so outcome and status can never diverge. It runs under a
  `lockForUpdate` row lock; re-finalizing the same outcome is a no-op;
  changing a terminal outcome throws `TerminalLessonOutcomeException`.
- **`LessonOutcomeFinalized` fires exactly once per lesson** — it is
  dispatched inside the finalizing transaction and implements
  `ShouldDispatchAfterCommit` (as do `AttendanceRecorded`,
  `AttendanceFinalized`, `LessonOutcomeOverridden`). No earnings,
  refund, homework, review, or notification listeners are attached in
  17A; the existing `LessonCompleted` → earnings pipeline still fires
  through the lifecycle sync.

### Rules

- Attendance is **evidence, not mutation**: only confirmed bookings
  with an open lesson accept it; a cancelled booking rejects both
  recording and finalization and can never be reactivated.
- Qualifying attendance = explicit human `Attended` mark, or join
  evidence with merged duration ≥ `lessons.min_attendance_seconds`
  (default 0 = disabled-safe). No-show outcomes are rejected when they
  contradict qualifying attendance.
- Completion is impossible before `ends_at` (only the pre-existing
  admin/booking override-completion path may complete early); a
  reported technical issue blocks *system* completion — the sweep
  skips the lesson, and finalizing the determined `TechnicalIssue`
  outcome parks the status as Disputed for a human decision.
- Explicit human attendance marks win over provider evidence:
  `FinalizeAttendanceAction` only fills party statuses still Pending.
- Admin correction goes through `override()` — requires
  `OverrideOutcome:Lesson` + a reason, writes previous/new values via
  `AuditTrailService::logOverride`, bumps `outcome_version`, and may
  force the status machine (`TransitionLessonAction` `$force`).
- All timestamps are stored UTC; audit events (`lessons` log):
  `lesson_attendance_recorded`, `lesson_attendance_finalized`,
  `lesson_outcome_finalized`, `lesson_outcome_overridden`.
- New permissions (seeded by `LessonPermissionSeeder`, granted to
  managers): `OverrideOutcome:Lesson`, `InspectAttendance:Lesson`.
  Instructor confirmation reuses `markAttendance` policy (own lessons).

## Automated Finalization

`lessons:finalize-due` (scheduled every 5 min, `withoutOverlapping`,
logs to `lessons-finalize-due.log`) — the evidence-driven finalizer:
`LessonFinalizationService` seals due attendance records, runs
`DetermineLessonOutcomeAction`, and finalizes through
`LessonOutcomeService` → `FinalizeLessonOutcomeAction`. It never
writes outcomes, statuses, bookings, or earnings itself.

- **Master switch** `lessons.automated_finalization_enabled` ships
  **OFF** — do not enable until providers feed attendance evidence,
  or evidence-less lessons would finalize as both-absent instead of
  auto-completing. While ON, the legacy lenient sweep
  (`lessons:auto-complete` / `autoCompleteDue`) defers (returns 0), so
  exactly one automation policy runs at a time.
- **Timing** (all minutes after `ends_at`): pickup requires the
  evidence window closed (`attendance_finalize_delay_minutes`, 30);
  then per-outcome gates — Completed waits
  `auto_complete_grace_minutes` (reused, 1440), no-shows their
  `student_/instructor_no_show_grace_minutes` (0), BothAbsent the max
  of both, TechnicalIssue holds everything until
  `technical_issue_window_minutes` (1440) closes, then finalizes as
  TechnicalIssue → Disputed for a human decision.
  `finalize_batch_size` (100) chunks the `openEndedBefore` cursor —
  deterministic order, never the full set in memory.
- **Protection**: skips non-open lessons, finalized outcomes (manual
  instructor completion, admin overrides, concurrent workers — the row
  lock + terminal guard make racers no-op), and lessons under an
  administrative hold (`late_evidence_reported_at` on the record).
  Cancelled bookings are only synchronized to the Cancelled outcome.
  Per-lesson `Throwable` isolation: one failure is logged
  (message only, no payloads) and the batch continues; the lesson
  stays open for the next run.
- **Late evidence**: once the record is sealed (or the lesson leaves
  its open state), new evidence is stored in the event log flagged
  `is_late` — excluded from aggregates, audited
  (`lesson_attendance_late_evidence`), and the record's
  `late_evidence_reported_at` flags the lesson for administrative
  inspection (and holds automation). Duplicates of late evidence stay
  fingerprint-deduped. Cancelled/pending bookings still reject
  evidence outright.

## Provider Attendance Ingestion

Provider-agnostic ingestion feeding the 17A evidence layer. Everything
accepted flows through `LessonAttendanceService` →
`RecordAttendanceAction` — the ingestion layer never writes aggregates
or outcomes, so fingerprint idempotency, interval-union overlap
merging, late-evidence flagging, and cancelled-booking rejection all
hold unchanged. Both switches ship **OFF**
(`meeting.attendance_webhooks_enabled`, `meeting.attendance_sync_enabled`);
enabling them feeds evidence only — automated outcomes stay separately
gated behind `lessons.automated_finalization_enabled`.

- **Capability contract** `MeetingAttendanceProviderInterface`
  (verify signature / parse webhook / fetch attendance / supports
  flags) — discovered via `instanceof` on registry providers; existing
  providers unchanged. Normalized DTOs (`ProviderAttendanceEvent`,
  `ProviderAttendanceWebhook`) are the only shapes that cross into the
  domain: participant references are **sha256-hashed at construction**
  (raw refs/emails never persist or log) and metadata passes
  `AttendanceMetadataSanitizer` (no tokens/emails/phones/links/
  transcripts/raw payloads).
- **Webhook** `POST /api/webhooks/meetings/attendance/{provider}`
  (`throttle:60,1`): signature verified before parsing (401), unknown/
  disabled/attendance-incapable providers 404, malformed payloads 422
  with a generic message, replays 200 "duplicate" via the unique
  `(provider, provider_event_id)` index on
  `meeting_attendance_provider_events` (an ops log that stores only
  sanitized normalized events — never raw payloads, unlike the payment
  side's encrypted copies, by design). Accepted envelopes queue
  `ProcessMeetingAttendanceWebhook` (notifications queue, 5 tries,
  backoff, `ShouldBeUnique` + row-lock claim).
- **Participant resolution**
  (`MeetingAttendanceParticipantResolver`): stored data only — the
  meeting's `metadata.attendance_participants` map, then the
  `user:{id}` convention — compared hash-to-hash. A provider role hint
  may only corroborate; contradiction (spoof), unknown, or ambiguous
  participants settle the event as an operational `review` row and
  never create attendance. Unknown meetings likewise.
- **Reconciliation** `meetings:sync-attendance {--force}` (scheduled
  every 15 min, `withoutOverlapping` + `onOneServer`): pulls sessions
  for Created meetings ended within
  [`attendance_sync_max_age_hours`, `attendance_sync_delay_minutes`],
  chunked by `attendance_sync_batch_size`, per-meeting failure
  isolation, bounded retries (`attendance_sync_max_attempts` within
  `attendance_sync_retry_minutes` of the meeting end, then
  `failed_permanent`), settled meetings never re-fetched unless
  `--force`. Providers without sync support are marked `unsupported`
  and skipped. Webhook+sync overlap can't double count — different
  sources, interval union.
- **FakeMeetingProvider** (`fake`) exists for attendance simulation
  and is registered **only in the testing environment** — the guard
  test in `BookingMeetingTest` now asserts exactly that. No real
  adapter yet: Zoom's integration has no attendance-report API surface
  or webhook secret token configured, and Google Meet/manual expose no
  attendance API — documented dependency for a later phase.

## Manual Confirmation & Technical-Issue Review

The participant fallback when provider attendance is unavailable, plus
the admin review desk. No pre-existing support/dispute module existed
to integrate with, so both submission types share one small review
lifecycle (`LessonReviewStatus`: pending → under_review →
accepted/rejected, plus duplicate/withdrawn/expired) — settled rows
are never edited, only re-decided via a new decision that conflicts.

- **Attendance confirmations**
  (`lesson_attendance_confirmations`, append-only):
  `LessonConfirmationService::submitConfirmation()` — participant role
  is always derived from the lesson's stored participants (request
  roles/ids are never trusted), submission requires the lesson to have
  started, cancelled lessons reject, identical claims dedup on a
  fingerprint, conflicting claims create new rows (history preserved).
  A claim needs a join time or attended minutes — a bare "I attended"
  never invents duration. Claims touch nothing until an admin accepts.
- **Technical-issue reports** (`lesson_technical_issue_reports`,
  categories: unable_to_join / invalid_meeting_link / audio / video /
  internet_disconnection / provider_outage / other; optional media
  evidence via a Spatie collection, images/PDF only): an in-window,
  pre-finalization report places the **outcome hold** through the
  existing evidence pathway (attendance-record
  `technical_issue_reported_at` → 17B never auto-completes or
  no-shows). Late or post-finalization reports are stored flagged
  (`flagged_late` / `after_finalization`) for review only — they can
  never hijack or rewrite an outcome. `AttendanceSource` gained
  `student_confirmation`; a participant may record evidence under
  their OWN confirmation source (LessonAttendanceService authorization
  extension) — never the other party's.
- **Admin review** (`LessonReviewService`, permission
  `ReviewAttendance:Lesson`, seeded to managers): accept/reject
  confirmations (acceptance feeds `LessonAttendanceService` with a
  stable `confirmation:{id}` event id — re-acceptance dedups), confirm/
  reject technical issues (rejecting the last supporting report
  releases the hold), request additional evidence (back to
  under_review), resolve ambiguous 17C provider events by assigning
  the participant, and finalize/override outcomes strictly through the
  the same outcome service described above. Every decision: row-locked (two admins
  can't resolve differently — the loser conflicts; repeats are
  idempotent), mandatory reason, audited with previous/new status
  (`lesson_attendance_confirmation_*`, `lesson_technical_issue_*`,
  `lesson_review_evidence_requested`,
  `lesson_ambiguous_evidence_resolved`).
- Policies: `submitAttendance` / `reportTechnicalIssue` (participants
  only), `reviewAttendance` (staff). No financial, homework, review,
  or notification side effects.

## Financial Disposition & Hold Foundation

The idempotent decision bridge between finalized outcomes and the
Wallet / Instructor Earnings / Settlement domains — classification and
holds only; it never credits refunds, reverses ledger entries, creates
earnings, or changes amounts. Gated by
`instructor_earnings.financial_disposition_enabled` (ships **OFF**).

- **One record per lesson** (`lesson_financial_dispositions`, unique
  `lesson_id`): outcome snapshot, student/instructor dispositions,
  processing status, reason code, linked earning + payment reference,
  admin-hold flag, version, `history` (previous snapshots appended on
  re-evaluation — never deleted).
- **Classification** — `ClassifyLessonFinancialDisposition` (queued)
  consumes `LessonOutcomeFinalized` exactly once per lesson:
  Completed-paid → existing completion earning (linked, no action);
  Completed-demo → no earning; StudentNoShow → no refund +
  compensation policy (`student_no_show_compensation_policy`, default
  manual_review); InstructorNoShow → full wallet refund required
  (ready) + no earning; BothAbsent → `both_absent_financial_policy`
  (default manual_review); TechnicalIssue → policy review + hold
  existing earning; Cancelled → existing cancellation pipeline,
  untouched. Never inferred from booking status alone; earning
  creation stays exclusively with `CreateEarningOnLessonCompleted`.
- **Holds** reuse `InstructorEarningService::holdForDispute()` —
  DisputedHold detaches from open batches and is excluded by the
  `settleable` scope, so held earnings can never enter a settlement
  batch. Idempotent (already-held earnings are left alone). Settled/
  reversed earnings are NEVER altered: conflicts set `admin_hold` +
  manual review (`settled_earning_conflict`).
- **Overrides** — `ReevaluateLessonFinancialDisposition` consumes
  `LessonOutcomeOverridden`: history preserved, version bumped,
  resolution cleared (a new decision is required). Completed→other
  holds the unsettled earning; other→Completed with no earning records
  `earning_reconciliation_required` (creation deferred); an
  already-refunded booking routes to `refund_already_completed`
  manual review.
- **Admin resolution** (`LessonFinancialDispositionService`,
  permission `ResolveFinancialDisposition:Lesson`, seeded to
  managers): approve / keep on hold / reject / mark ready for wallet
  refund execution / mark ready for earning reconciliation. Row-locked,
  reason mandatory, audited (`instructor_earnings` log,
  `lesson_financial_disposition_*` events with previous/new state).
  Resolved records re-open only via an outcome override.

## Wallet Refund Execution

Executes approved 17E refund dispositions — **wallet-only** (Version 1
never calls a gateway refund API; gateway-paid lessons are credited to
the student wallet with the original payment record preserved) and
**student-side only**. Gated by
`instructor_earnings.lesson_refund_execution_enabled` (ships **OFF**).

- **Domain home**: `app/Wallet/` (`ExecuteLessonWalletRefundAction`,
  `LessonWalletRefundService` + contract, `LessonRefundCompleted`
  event) — deliberately NOT `app/Earnings`, whose cross-domain
  isolation guard forbids reading `BookingPayment`; the refund is
  student-money work and lives with the wallet.
- **Eligibility** (revalidated under a row lock): status Ready +
  student disposition FullWalletRefundRequired + no admin hold + no
  existing refund. That covers instructor no-show (auto-Ready),
  both-absent under the `refund` policy, and any admin
  `markReadyForRefund` approval (e.g. technical issue). Policy-review
  cases are never auto-refunded.
- **Original charge**: the single Captured `BookingPayment` row —
  actual charged amount and currency, never current pricing, never
  converted, never more than charged. Missing/ambiguous charges and
  currency mismatches defer to manual review (`admin_hold`);
  unpaid/demo bookings never refund (the 17E matrix downgrades the
  refund for unpaid bookings).
- **Ledger**: one immutable credit via `WalletLedgerService::credit()`
  with idempotency key `lesson-refund:{disposition}:v{version}` —
  duplicate runs, concurrent workers, and post-crash retries all
  resolve to the single entry. The entry links lesson, booking, source
  payment, and disposition (`source_type`/`source_id` + metadata); the
  disposition stores `refund_ledger_entry_id` + `refund_executed_at`
  and resolves as `refund_completed`. Cancellation-flow refunds are
  detected by their `cancellation-refund:{payment}` key and linked,
  never duplicated; provider-side refunds defer to manual review.
- **Failure safety**: eligibility problems are committed *decisions*
  (manual review + reason); infrastructure failures throw and roll
  back — a disposition can never be Resolved without its ledger entry.
  An outcome override after a refund never debits the wallet and never
  deletes the credit: the disposition re-opens as
  `refund_reconciliation_required` (manual review + admin hold) with
  refund linkage and history preserved.
- **Trigger**: `lessons:process-refunds` (scheduled every 15 min,
  `withoutOverlapping` + `onOneServer`, batched, per-record failure
  isolation) or immediate admin execution through the service
  (permission `ResolveFinancialDisposition:Lesson`). Audited via
  `AuditTrailService` (`lesson_refund_executed` with actor, amount,
  currency, previous/new balance, reason).

## Earning Reconciliation Execution

Instructor-side counterpart of 17F: executes approved reconciliation
dispositions (status Ready + reason `earning_reconciliation_required`
or `student_no_show_earning_reconciliation_required`) through the
existing earning domain — never a second calculator, model, or
settlement path, never student wallets/payments, never settled money.
Gated by `instructor_earnings.earning_reconciliation_execution_enabled`
(ships **OFF**).

- **Creation** — `InstructorEarningService::createForReconciliation()`
  (new): same compensation resolver (agreement in force at scheduled
  start, never student price), same creation action, same snapshot as
  `createFromLesson`; only the completed-status requirement is
  replaced by the explicit approval. Covers a completed paid lesson
  missing its earning, approved student no-show compensation (policy
  `normal_earning` routes straight to Ready), and approved both-absent
  compensation. Demo-policy-none and periodic bases stay benign
  non-creations; instructor no-show, cancelled, unpaid, and
  agreement-less lessons defer to manual review — approval cannot
  conjure eligibility.
- **Corrections on existing earnings**
  (`ExecuteLessonEarningReconciliationAction`): outcome Completed +
  DisputedHold → restore (`holdRestore()`, the newly-exposed
  DisputedHold→PendingHold transition) then release;
  TechnicalIssue → `holdForDispute()` (idempotent); corrected against
  the instructor → reverse (detaching from any open batch via the hold
  first); already Reversed → idempotent no-op; Settled/terminal →
  never mutated, parked as `settled_earning_manual_recovery`.
  Snapshots are immutable through every correction.
- **Approvals lift holds**: 17E's `approve`/`markReadyFor*` now clear
  `admin_hold` — an explicit approval IS the decision the hold was
  waiting for.
- **Trigger** — `lessons:process-earning-reconciliation` (every
  15 min, `withoutOverlapping` + `onOneServer`, batched, per-record
  isolation) or direct admin execution
  (`ResolveFinancialDisposition:Lesson`). Row-locked and revalidated;
  duplicate/concurrent execution adjusts once. Audited
  (`lesson_earning_reconciled` with action, previous/new earning
  status, amount, currency, actor); adjustment history = these audit
  entries + the earning domain's own transition audits (no new
  adjustment model needed). After-commit event:
  `LessonEarningReconciled` (no listeners yet).

## Deferred (do not build yet)

Homework, reviews, certificates, instructor payout, wallet
debit/recharge, recording storage, group classes, recurring lesson
generation, full learning-progress engine, advanced attendance
analytics, lesson notifications mapping, student/instructor frontend
lesson UI, join-click-based automatic attendance, real provider
attendance adapters (Zoom attendance-report API + webhook secret
token; Google Meet has no attendance API) and admin UI for the
attendance review queue, outcome-driven financial corrections
after an admin override.

## Tests

`tests/Feature/Lesson/` — `LessonLifecycleTest` (creation trigger,
eligibility, snapshot, transitions, attendance, completion, no-show,
dispute, booking↔lesson sync both directions, auto-complete command),
`LessonAttendanceOutcomeTest` (evidence recording,
idempotent/out-of-order/overlapping ingestion, authorization,
determinations, terminal protection, override + audit, concurrency,
exactly-once event, UTC safety), `LessonAutomatedFinalizationTest`
(kill switch + legacy deferral, determination matrix,
technical-issue window, delays, manual/override protection, cancelled
sync, idempotent/concurrent runs, batch isolation, late evidence,
earnings exactly-once, scheduler registration, UTC safety),
`LessonPermissionSeederTest`, `LessonAdminPanelTest`;
`tests/Feature/Booking/MeetingAttendanceIngestionTest` (
signed webhooks, signature/malformed/unknown rejections, participant
resolution + spoof protection, normalization semantics, webhook+sync
overlap, sync idempotency/failure isolation/retries, privacy
guarantees, finalization-stays-off), `LessonManualReviewTest`
(participant claims + authorization, idempotency/history,
technical-issue windows + holds, sanitization, admin review
accept/reject/conflict, outcome delegation, no side effects),
`tests/Feature/Earnings/LessonFinancialDispositionTest` (
classification matrix, earning holds + settlement exclusion, override
history, settled-money protection, admin resolution, kill switch,
no-money-movement guarantees), `tests/Feature/Earnings/
LessonWalletRefundTest` (eligibility matrix, original-charge
amounts/currency, gateway-untouched wallet credits, ledger linkage,
idempotency/concurrency, cancellation dedup, failure safety,
override-after-refund reconciliation, earning isolation),
`tests/Feature/Earnings/LessonEarningReconciliationTest` (
creation eligibility matrix, agreement-not-price compensation,
snapshot immutability, hold/restore-release/reverse corrections,
settled-money protection, idempotency/concurrency, audit history,
wallet/payment isolation).
