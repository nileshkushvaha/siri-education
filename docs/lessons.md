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

## Attendance & Lesson Outcome (Phase 17A)

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

## Deferred (do not build yet)

Homework, reviews, certificates, instructor payout, wallet
debit/recharge, recording storage, group classes, recurring lesson
generation, full learning-progress engine, advanced attendance
analytics, lesson notifications mapping, student/instructor frontend
lesson UI, join-click-based automatic attendance, meeting-provider
webhook endpoints / sync jobs feeding the attendance layer (17B+),
outcome-driven financial corrections after an admin override.

## Tests

`tests/Feature/Lesson/` — `LessonLifecycleTest` (creation trigger,
eligibility, snapshot, transitions, attendance, completion, no-show,
dispute, booking↔lesson sync both directions, auto-complete command),
`LessonAttendanceOutcomeTest` (Phase 17A: evidence recording,
idempotent/out-of-order/overlapping ingestion, authorization,
determinations, terminal protection, override + audit, concurrency,
exactly-once event, UTC safety), `LessonPermissionSeederTest`,
`LessonAdminPanelTest`.
