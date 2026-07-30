# Phase 7 Booking Flow Hardening & Slot Consumption

## Decision

Phase 7.0 audited the existing booking engine (built pre-Phase-6) against
how it consumes the Phase 6 dynamic instructor availability foundation.
The architecture was found sound and reusable as-is; four concrete gaps
were hardened in Phase 7.1 without any schema change. Full architectural
detail (tables, DTOs, notifications, payment placeholder, guest API,
analytics) remains in `docs/booking.md` — this document covers what is
specific to Phase 7: the audit findings, the hardening applied, and what
is intentionally deferred.

## Phase 6 Prerequisite

`docs/audits/phase-6-instructor-availability-foundation-final-audit.md`:
score 96/100, SAFE TO PROCEED TO PHASE 7, no blocking issues. Two minor
Phase 6 findings were closed first in this phase (see "Phase 6 cleanup"
below) before any Phase 7 work began.

## Existing Booking Architecture (confirmed, reused as-is)

```
Controller / Livewire / FormRequest
        │  builds CreateBookingData / GuestBookingData / StudentBookingData
        ▼
GuestBookingService / StudentBookingService   ← teacher choice, recurrence, guest spam guard
        │
        ▼
BookingService::request()                     ← orchestration
        │  1. BookingValidationPipeline (fast-fail global + type rules)
        │  2. BookingRepository::withHostLock()  (MySQL GET_LOCK per host)
        │       DB::transaction {
        │         3. duplicateExists() re-check (lockForUpdate)
        │         4. AvailabilityService::ensureAvailable() re-check
        │         5. capacity check (shared-slot types)
        │         6. CreateBookingAction (persist + activity log)
        │       }
        │  7. Domain events (BookingRequested, BookingConfirmed if auto)
        ▼
BookingRepository                              ← all Eloquent queries, locking
        ▼
Booking model
```

This orchestration, the host-lock + re-check pattern, the five-state
`BookingStatus` / `BookingPaymentStatus` enums, the transition-guarded
Actions, and `BookingPolicy` were all confirmed correct in the audit and
were **not** rewritten — Phase 7 only closes the four gaps below.

## Availability Consumption Flow

Slot generation is unchanged from Phase 6: `AvailabilityService::slots()`
derives candidates from `SlotGenerator` over weekly windows (minus leave,
holidays, existing bookings with buffer, the bookable window, and the
daily cap), and `ensureAvailable()` is the single source of truth re-run
both as a fast-fail rule (`TeacherAvailabilityRule`, before the host lock)
and again inside the lock by `BookingService::request()`/`reschedule()`.

**Phase 7 hardening**: `ensureAvailable()` did not verify the host was a
currently active, bookable instructor — only availability windows,
holidays, blackouts, overlaps, and the daily cap. The bookable-status
check lived only in the *calling* service (`GuestBookingService`/
`StudentBookingService`, via `TeacherCandidateRepository::isEligible()`/
`isApprovedTeacher()`), which runs *before* the host lock is acquired.
An instructor could be rejected or deactivated between that pre-check and
lock acquisition, and the booking would still succeed. `ensureAvailable()`
now re-verifies `TeacherCandidateRepositoryInterface::isApprovedTeacher()`
as its first check, so the same guard now runs at both the fast-fail
layer and inside the race-safe lock. `AvailabilityService::slots()` gained
the same guard (returns an empty collection for a non-bookable host)
so slot listings never leak times for an instructor who can't actually be
booked.

`TeacherCandidateRepository::isApprovedTeacher()` / `eligible()` /
`availableSubjects()` were also widened to require `users.status = active`
in addition to a bookable `instructor_status` — previously only the
profile-level status was checked, not the account-level one.

## Marketplace Handoff

`BookingWizardService::lockedInstructor()` still does the server-side
slug → bookable-instructor lookup at `mount()` time; this was already
correct. The gap was that `BookingWizard::$lockedInstructorId` (and
`$lockedInstructorName`) were plain public Livewire properties with no
protection — Livewire allows any non-guarded public property to be
overwritten by a client-submitted "updates" payload on subsequent
requests, independent of the component's rendered snapshot. A crafted
request could therefore swap the marketplace-locked instructor for a
different (still-eligible) one between wizard steps.

Both properties now carry Livewire's `#[Locked]` attribute: any attempt
to set them after `mount()` — from the client or a crafted request —
throws `CannotUpdateLockedPropertyException` instead of silently
succeeding. The pure guest JSON API (`POST /api/v1/guest/bookings`) was
confirmed to have no equivalent surface at all: it never accepts a
`teacher_id` field, so instructor selection only ever happens through the
assignment engine there.

## Race Safety

Confirmed already correct: `BookingRepository::withHostLock()` (MySQL
`GET_LOCK`/`RELEASE_LOCK` per host id) wraps a `DB::transaction`; every
race-sensitive read inside it (`duplicateExists`, `hasOverlap`,
`activeCountForDay`, `attendeeCountForSlot`) uses `lockForUpdate()`.
`ensureAvailable()`'s new bookable-status check (above) closes the one
gap found in this layer.

## Self-Booking Guard (new)

Nothing previously stopped a dual-role user (a student who is also an
approved instructor) from booking themselves — no rule, no DB constraint.
Added `App\Booking\Validation\Rules\SelfBookingRule`, registered in
`BookingService::GLOBAL_RULES` alongside the existing global rules. It
rejects `attendeeId === hostId` (guests always have a null `attendeeId`,
so this never touches the guest flow) with a plain `BookingException`,
consistent with every other domain rule.

## Demo vs Paid Booking Boundary

No new statuses were introduced — the existing `BookingStatus` (Pending/
Confirmed/Completed/Cancelled/NoShow) and `BookingPaymentStatus`
(NotRequired/Pending/Paid/Failed/Refunded) already encode every state
Phase 7 needs:

- Free/demo types: `payment_status = NotRequired`, auto-confirmed when the
  type doesn't require approval.
- Paid types: `status = Pending` + `payment_status = Pending` +
  `reserved_until` is exactly the "reservation awaiting payment" state —
  functionally equivalent to a `pending_payment` status without adding
  one. `BookingPaymentService` remains the clearly-marked PLACEHOLDER it
  was before Phase 7 (`fake` provider, no money moves) and was not
  touched — payment collection is still out of scope for this phase.

## Booking Lifecycle

Unchanged and confirmed correct: every transition goes through
`BookingStatus::canTransitionTo()`, enforced inside each Action
(`ConfirmBookingAction`, `CancelBookingAction`, `CompleteBookingAction`,
`RescheduleBookingAction`); admins never mutate `status` directly through
a raw form field. `BookingResource::canCreate()` returns `false` — there
is intentionally no admin Create page; bookings only come from the
engine.

## Admin / Filament Hardening

The Bookings resource's lifecycle actions (confirm/cancel/reschedule/
complete/no-show) were already service-backed with policy-authorized
row/bulk actions — unchanged.

**Phase 7 hardening**: `EditBooking`'s header `DeleteAction` /
`ForceDeleteAction` and `BookingsTable`'s bulk `DeleteBulkAction` /
`ForceDeleteBulkAction` used Filament's generic delete behavior. Because
Eloquent's `SoftDeletingScope` hides soft-deleted rows from every
`active()`/`upcoming()` scope automatically, soft-deleting (or
force-deleting) a still-Pending/Confirmed booking silently freed its slot
for `AvailabilityService` without ever going through
`CancelBookingAction` — no transition guard, no `BookingCancelled` event,
no notification, no recorded reason. Both row actions now `before()` +
`halt()` unless `$record->status->isTerminal()` (Completed/Cancelled/
NoShow), showing a clear notification instead. Both bulk actions now skip
non-terminal records individually and report a "N deleted, N skipped"
notification, matching the existing `cancel_selected` bulk action's
established skip-counting idiom in the same file. `RestoreAction` /
`RestoreBulkAction` were left untouched — restoring a soft-deleted record
back has no availability-integrity impact.

## Policies and Permissions

`BookingPolicy` was already correct and is unchanged: participants
(attendee or host) manage their own bookings via `isParticipant()`/
`isHost()`; every other ability requires an explicit Shield permission.
Re-verified in this phase: a student cannot `view` another student's
booking; an instructor cannot `view` a booking they don't host; an
unpermitted manager cannot `confirm`/`cancel` via Filament (the action is
hidden, not just denied).

## Activity Logging

Unchanged: `BookingRepository::logActivity()` records every lifecycle
transition to `booking_activities` (append-only), and `Booking`'s
`LogsActivity` trait mirrors status/payment/schedule changes to the
unified `activity_log` audit trail. No new logging was required — the
new guards (self-booking, bookable-status re-check, locked-instructor
tamper) all fail before any record is created, so there is nothing to
audit beyond the existing `BookingException`/`SlotUnavailableException`
surfaced to the caller.

## What Is Intentionally Not Built

Per the Phase 7 scope: payment collection, wallet, meeting creation,
homework, reviews, recurring-lesson expansion beyond the existing
`bookRecurring()`, and packages/subscriptions. No generated-slot or
reservation table was created — the audit did not find a case the
existing dynamic `AvailabilityService` + `bookings.reserved_until`
combination can't already handle safely.

## Future Integration

- **Payment**: `BookingPaymentService` stays a placeholder; a real
  `PaymentProviderInterface` implementation is a drop-in swap (one class +
  one registry line + a settings change), unchanged by this phase.
- **Wallet**: no coupling exists yet; a future wallet phase would hang off
  `BookingPaymentService::markPaid()`/`recordRefund()` without touching
  the booking engine itself.
- **Meeting**: `bookings.meeting_provider`/`meeting_ref`/`meeting_url`
  columns already exist (added pre-Phase-7) and are still unused; a future
  meeting phase would populate them on `BookingConfirmed`.

## Phase 6 Cleanup (completed first, in this phase)

1. The instructor availability warning banner claimed drafts could be
   saved without a timezone; the frontend "Add window" form actually
   hardcodes `is_active: true` and requires a timezone unconditionally.
   Banner copy corrected in
   `resources/views/livewire/frontend/instructor/availability-manager.blade.php`
   and the caveat documented in `docs/booking.md`; no behavior changed.
2. The two Filament row-delete "service-backed" tests in
   `InstructorAvailabilityHardeningTest.php` asserted only that *an*
   activity-log row existed, which would also pass for a hypothetical
   regression to a bare `Model::delete()` (the model's own automatic
   Spatie logging produces a row with the same `log_name`/`event`).
   Strengthened to assert the exact service-authored `description` and
   the `causer_id`/`teacher_id` properties, matching the already-correct
   bulk-delete test variants.

## Tests

`tests/Feature/Booking/BookingFlowHardeningTest.php` (29 tests): slot
consumption (generated/stale/outside-window/time-off/holiday/overlap/
buffer/min-notice/max-advance/daily-cap/non-bookable-instructor), race
safety (duplicate rejection, final-check-runs-even-if-caller-precheck-
bypassed, host-lock-blocks-two-attendees-same-slot), marketplace handoff
(`#[Locked]` proven via `CannotUpdateLockedPropertyException`, wizard
state stable across steps), guest booking (safe request, stale-slot
rejection, required-field validation, no out-of-scope records), student
booking (safe request, self-booking rejection, cross-student policy
denial, instructor-scoped view, no out-of-scope records), and admin/
permissions (unpermitted manager denied, Filament action hidden without
permission, non-terminal delete blocked, terminal delete allowed).

Full regression: `composer test` → **1888 tests passed, 4170 assertions**
(1859 baseline + 29 new), including every Phase 2–6 test file unchanged.

## Remaining Gaps

- Buffer/min-notice/max-advance/daily-cap are exercised with the existing
  `BookingSettings` object mutated per-test; no test covers every
  combination of overlapping settings simultaneously (not required by the
  audit — each rule is independently unit-covered by `SlotGeneratorTest`
  and now integration-covered here).
- True concurrent (multi-process/thread) double-booking is not
  practically testable in PHPUnit; race safety is demonstrated via
  sequential requests against the same lock/re-check path, matching the
  existing project convention (no change from pre-Phase-7 test style).
- Payment/wallet/meeting integration remains deliberately deferred to a
  future phase, as scoped.
