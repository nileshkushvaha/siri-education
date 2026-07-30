# Phase 13 — Lesson Lifecycle, Attendance & Completion Foundation

The layer that starts where a confirmed booking ends: one `lessons`
row per booking tracking scheduled → live → attendance → completion /
no-show / dispute / cancellation. The lesson **extends** the booking
lifecycle after confirmation — it never replaces the booking and never
stores payment data (price, provider ids, wallet details stay on the
booking). Module doc: `docs/lessons.md`.

## Lifecycle states

`LessonStatus` (single source of truth, `canTransitionTo()`; every
write goes through `TransitionLessonAction`):

```
Scheduled ──▶ Live ──▶ Completed ◀──▶ Disputed ──▶ Cancelled
    │           │──▶ StudentNoShow / InstructorNoShow / BothNoShow ──▶ Disputed
    └──▶ (any outcome directly — Live is optional)
```

Completed and the no-show outcomes may still be disputed; a dispute
resolves to Completed or Cancelled (admin). Cancelled is terminal.

## Booking → lesson trigger

`CreateLessonOnBookingConfirmed` (queued, `notifications` queue) is
the **only** automatic trigger — `BookingConfirmed` fires exactly once
per booking, from `BookingService::confirm()` (paid, after verified
payment) and the auto-confirm path in `request()`. It never fires on
payment initiation, frontend checkout success alone, or Option B late
terminal payments, so none of those can create a lesson.

Eligibility (re-checked in `LessonLifecycleService::isEligible()`,
listener is a thin trigger): booking `confirmed`, real attendee
(student) and host (instructor) — guest bookings never grow lessons —
concrete `starts_at`/`ends_at`, and payment terms settled:
`not_required` (free/demo) or `paid`. Cancelled/expired bookings fail
the `confirmed` check; pending/failed/refunded payments fail the
payment check. Creation is idempotent (`booking_id` unique + existing
row returned). Meeting failure never blocks lesson creation — the
lesson stays `scheduled` while the meeting is retried (see the Phase
11 addendum).

## Topic snapshot

`CreateLessonFromBookingAction` snapshots the Phase 12.5 academic
context: `subject_topic_id` from `meta.topic_id`, `subject_id` from
the topic (or `meta.subject` slug), and a whitelisted-scalars-only
`metadata` blob (`booking_reference`, `booking_type`, `subject`,
`topic`, `grade`). Bookings snapshot a grade **int**, not a level id,
so `academic_level_id` is filled only when exactly one active academic
level covers that grade — overlapping or country-specific levels are
ambiguous and leave the FK null, with `metadata.grade` remaining the
source of truth. Hostile/extra booking meta keys are never copied.

## Attendance model

Per party (`student_attendance_status`, `instructor_attendance_status`):
`pending` (default) → `attended` (+`*_attended_at`) or `no_show`.
Manual marking only this phase — no Zoom/Google attendance API. A
no-show may be recorded only `no_show_grace_minutes` after
`starts_at`; admin/system paths pass `override: true`. Attendance can
only change while the lesson is open (scheduled/live) and never back
to pending.

## Completion model

`LessonLifecycleService::complete()`:

- **Idempotent** — completing a completed lesson is a no-op.
- Without override: the lesson must have **ended**, and any required
  confirmations (`require_instructor_completion`,
  `require_student_attendance`) must be recorded.
- Admin panel completion passes `override: true` (G: admin override).
- Sets `completed_at`, `completed_by` (null when automatic),
  `completion_notes`; unflagged parties count as attended on manual
  completion (auto-completion leaves attendance as recorded).
- Booking sync: the booking status enum **does** have `completed`, so
  a still-confirmed parent booking is safely moved through
  `BookingServiceInterface::complete()` — events, notifications, and
  audit fire through the engine; already-finalized bookings are left
  alone. No payout is triggered — completion only prepares the future
  payout phase.

## No-show model

`finalizeNoShow()` derives `student_no_show` / `instructor_no_show` /
`both_no_show` from the recorded attendance (at least one no-show
required) and syncs the booking to `no_show`. A booking-level no-show
marked in the Booking admin flows back via
`SyncLessonOnBookingCompleted`: with no attendance recorded it marks
the student no-show (booking no-show has always meant the attendee in
this 1-to-1 flow) and finalizes. No wallet mutation, no refund policy
change, no payout.

## Auto-complete command & settings

`lessons:auto-complete` — scheduled every 15 min
(routes/console.php), `withoutOverlapping`, logs to
`storage/logs/lessons-auto-complete.log`. Idempotent: sweeps **open**
lessons whose `ends_at` passed the grace period; recorded no-shows
become no-show outcomes; lessons missing a required attendance
confirmation are left open for a manual decision; everything else
completes with `auto_completed_at`. Disputed/cancelled/finalized
lessons never re-enter the sweep. No payment/wallet/payout changes.

`LessonSettings` (Spatie group `lessons`):

| Setting | Default | Meaning |
|---|---|---|
| `auto_complete_enabled` | true | Kill switch for the sweep |
| `auto_complete_grace_minutes` | 1440 | Minutes after `ends_at` before auto-finalize |
| `no_show_grace_minutes` | 15 | Minutes after `starts_at` before a no-show may be recorded |
| `require_instructor_completion` | false | Completion (manual + auto) requires instructor attended |
| `require_student_attendance` | false | Completion (manual + auto) requires student attended |

## Visibility

- **Admin** (`LessonResource`, Booking group, deny-by-default via
  `LessonPermissionSeeder`): list + filters (status, instructor,
  student, subject, topic, date, trashed), lifecycle row actions
  through the service only, participant-facing join link only
  (`host_url`/`password` stay hidden), subject/topic snapshot columns.
  No create/edit pages — no raw metadata editing, no payment fields.
  The Booking admin also shows a toggleable lesson-status badge.
- **Student/instructor**: no frontend lesson UI this phase (next
  sub-phase). `LessonPolicy` already enforces the boundaries:
  participants view their own lessons; the instructor of a lesson may
  mark attendance and complete it; students may dispute; strangers are
  denied. The lesson row/serialization contains no student price,
  payment provider ids, wallet details, or platform margin — verified
  by test.

## Explicitly not built (deferred)

Instructor payout (Phase 14), wallet debit/recharge, recording
storage, homework, reviews, certificates, group classes, recurring
lesson generation, learning-progress engine, attendance analytics,
participant-facing lesson notifications (admin alerts for no-show and
dispute ARE mapped — see Phase 12 addendum), student/instructor
lesson UI, Zoom/Google join-based automatic attendance (future: their
webhooks/APIs can drive `mark*Attendance()` — the service API is
already shaped for it). Guest booking/payment stays disabled;
payment/meeting providers and the pricing matrix are untouched.

## Tests

`tests/Feature/Lesson/`: `LessonLifecycleTest` (34 tests — creation
eligibility incl. cancelled/expired/late-terminal-payment, duplicate
event idempotency, snapshot (topic + unambiguous academic level) +
metadata whitelist, attendance + grace,
completion rules + idempotency, no-show derivation, dispute
resolution, booking↔lesson sync both directions, auto-complete
sweep/kill-switch/required-confirmation, wallet/payout boundary),
`LessonPermissionSeederTest`, `LessonAdminPanelTest`.
