# Phase 7.2 Booking Flow Hardening Audit

## Executive Decision

Readiness score: **95/100**

Decision: **SAFE TO PROCEED**

Blocking issues: **none**

This is an independent re-verification of the Phase 7.1 booking hardening
— every claim below was checked by reading the current source and by
running the verification commands fresh in this session, not by trusting
the Phase 7.1 summary. All four gaps identified by the Phase 7.0 audit
(self-booking, the bookable-status race, the tamperable locked-instructor
property, and unsafe generic Filament delete) are confirmed closed and
logically sound. Two minor, non-blocking findings were identified (below);
neither is a production defect.

## Prerequisite Gate

Verified prerequisite: Phase 6.4 final audit
(`docs/audits/phase-6-instructor-availability-foundation-final-audit.md`),
96/100, SAFE TO PROCEED TO PHASE 7, no blocking issues.

## Race-Safety and Self-Booking Fixes — Verified

Re-read `AvailabilityService`, `TeacherCandidateRepository`,
`SelfBookingRule`, and `BookingService` in full:

- `AvailabilityService::ensureAvailable()` now checks
  `TeacherCandidateRepositoryInterface::isApprovedTeacher($hostId)`
  **first**, before the window/holiday/blackout/overlap/daily-cap checks
  — correct ordering (fail on the most fundamental condition first), and
  it throws the same `SlotUnavailableException` type as every other
  rejection in the method, so callers don't need new error handling.
- Confirmed via `grep` that `ensureAvailable()` has exactly three callers:
  `TeacherAvailabilityRule` (pre-lock fast-fail), and
  `BookingService::request()` / `reschedule()` (inside the host lock) —
  the fix lands in the one shared method both call, so both the fast-fail
  path and the in-lock re-check benefit automatically without duplicated
  logic.
- **Bonus finding, not previously documented**: `TeacherAssignmentService::assign()`
  (the guest-flow auto-assignment engine) also calls `ensureAvailable()`
  per candidate as its "hard filter" phase. This third call site benefits
  from the same fix at no extra cost — a teacher who becomes non-bookable
  between the candidate list being built and assignment is now also
  correctly excluded there.
- `TeacherCandidateRepository::eligible()` / `isApprovedTeacher()` /
  `availableSubjects()` were widened to also require `users.status =
  active` (previously only `instructor_status`), closing the same gap at
  the discovery layer, not just the final-check layer.
- `SelfBookingRule` is registered in `BookingService::GLOBAL_RULES` as a
  fast-fail rule (correct — `attendeeId`/`hostId` are static within a
  request, so there is no race condition for this check to guard against;
  it does not need to re-run inside the host lock).

## Livewire Lock and Filament Delete Hardening — Verified

- `BookingWizard::$lockedInstructorId` and `$lockedInstructorName` both
  carry `#[Locked]` (`Livewire\Attributes\Locked`), confirmed by direct
  file read. Nothing in the component's own code reassigns either
  property outside `mount()`, so the attribute has no legitimate
  server-side assignment to conflict with.
- `EditBooking`: both `DeleteAction` and `ForceDeleteAction` use
  `->before()` + `$action->halt()`, guarded by
  `$record->status->isTerminal()` — confirmed to run *after* the
  confirmation modal but *before* the actual delete, which is the correct
  hook point in Filament's action lifecycle.
- `BookingsTable`: both `DeleteBulkAction` and `ForceDeleteBulkAction`
  route through a shared `deleteTerminalOnly()` helper that skips
  non-terminal records individually and reports a "N deleted, N skipped"
  notification — the same idiom already used by the file's pre-existing
  `cancel_selected` bulk action, so this isn't a new pattern introduced
  just for this fix.
- `RestoreAction` / `RestoreBulkAction` were correctly left unguarded —
  restoring a soft-deleted record has no availability-integrity
  implication.

## Duplicate-Structure and Out-of-Scope Sweep

- `git diff --stat` against the Phase 6 baseline commit (`dc2da31`) shows
  only existing files modified across the whole session (Phase 6.3 + 6.4
  cleanup + Phase 7.1); a filesystem search for anything newer than that
  commit under `app/Models`, `database/migrations`, and
  `app/Filament/Resources` found no new models, migrations, or resources
  — only the already-known modified Table/Page files.
- `SelfBookingRule.php` is the only new PHP class this phase, and it's a
  validation rule (matching the existing `{Constraint}Rule` naming
  convention), not a model, migration, or duplicate structure.
- `git diff` against the baseline for `BookingPaymentService.php`,
  `Booking.php`, and `database/migrations/` is empty — confirmed
  completely untouched. `bookings.meeting_provider`/`meeting_ref`/
  `meeting_url` remain unused columns; no wallet table exists.
- `php artisan migrate:status`: still batch **36** (unchanged since Phase
  6.2). `php artisan route:list`: still **218** routes (unchanged).

## Test Coverage Audit

`composer test` (`php artisan test --env=testing`, run fresh in this
audit session):

```
1888 tests passed, 4170 assertions
```

(1859 at the Phase 6.4 baseline + 29 new in
`BookingFlowHardeningTest.php`.) Reviewed the new test file method-by-
method:

- Slot-consumption tests (generated/stale/outside-window/time-off/
  holiday/overlap/buffer/min-notice/max-advance/daily-cap/non-bookable)
  each isolate a single rule with no cross-contamination between test
  cases (verified the narrow 09:00–11:00 fixture window makes the
  "outside availability" case unambiguous, and that settings mutations
  are scoped per-test).
- The duplicate-vs-overlap race tests
  (`test_concurrent_double_booking_attempt_is_blocked` and
  `test_host_lock_prevents_two_different_attendees_taking_the_same_slot`)
  correctly exercise two distinct code paths (`duplicateExists()` vs
  `hasOverlap()`), not the same assertion twice.
- The Filament delete-guard tests correctly isolate the new guard's
  effect specifically: the test manager is granted full `Delete:Booking`
  permission, so if the guard didn't exist, deletion would succeed —
  meaning a passing "blocked" assertion can only be explained by the new
  `before()`/`halt()` check, not by an authorization failure.

**Minor finding**: `test_final_availability_check_runs_even_when_caller_precheck_is_bypassed`
calls `BookingService::request()` directly for a non-bookable host,
skipping `GuestBookingService`'s own pre-check, and asserts a
`SlotUnavailableException`. This is a real and useful test, but it cannot
actually distinguish whether the pre-lock fast-fail rule
(`TeacherAvailabilityRule`) or the in-lock re-check inside
`BookingService::request()` is what threw — both call the identical,
now-shared `ensureAvailable()` method, and the fast-fail rule runs first,
so it would always throw before the lock is ever acquired. This isn't a
functional gap (there is genuinely only one check to get right, and it
is correct), but the test's docblock claims more precision about *which*
layer caught it than the test can actually demonstrate. Non-blocking;
worth a comment adjustment if this file is revisited, not a code or
coverage fix.

## Minor UX Finding (non-blocking)

`EditBooking`'s `DeleteAction`/`ForceDeleteAction` guard via `before()` +
`halt()`, which runs after the confirmation modal is submitted — the
button itself remains visible and clickable for a non-terminal booking,
and the user only learns it's blocked after confirming. A `->visible(fn
() => $this->getRecord()->status->isTerminal())` would proactively hide
the button for a cleaner experience, matching how Phase 6 handled
permission-based hiding for the same kind of action. This does not affect
correctness — the delete is still safely blocked either way — so it's
recorded as optional polish, not a defect.

## Policies and Permissions — Re-Verified

- A student cannot `view` another student's booking; an instructor
  cannot `view` a booking they don't host; an unpermitted manager cannot
  `confirm`/`cancel` a booking they don't participate in — all
  reconfirmed via direct `User::can()` assertions against the unmodified
  `BookingPolicy`.
- The Filament `confirm`/`cancel` row actions are correctly *hidden*
  (not just denied) for a manager who can view the list but lacks the
  specific ability — verified via `assertTableActionHidden`, avoiding the
  same "list page never mounts" pitfall documented in the Phase 6.4
  audit (this time the test explicitly grants `ViewAny:Booking` /
  `View:Booking` alongside the deliberately-withheld action permissions).

## Commands

| Command | Result |
|---|---|
| `composer test` (`php artisan test --env=testing`) | Passed: 1888 tests, 4170 assertions |
| `php artisan migrate:status` | Passed; batch 36 still latest (unchanged since Phase 6.2) |
| `php artisan route:list` | Passed; 218 routes (unchanged) |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |

## Remaining Gaps

1. The test-precision nuance above (`test_final_availability_check_runs_even_when_caller_precheck_is_bypassed`'s
   docblock overclaims which layer caught the rejection).
2. The Filament delete-button visibility UX note above.
3. Everything already recorded as deferred in
   `docs/architecture/phase-7-booking-flow-hardening-slot-consumption.md`
   (payment/wallet/meeting integration, exhaustive `BookingSettings`
   combination coverage, true multi-process concurrency testing) remains
   deferred by design, not by gap.

## Final Assessment

Phase 7.1's four hardening fixes are verified correct and complete by
independent re-reading of the source, not by re-stating the prior
session's claims. No duplicate or out-of-scope structures exist. Full
regression suite green. The two findings above are cosmetic/documentation
nuances with no functional or security impact.

Decision: **SAFE TO PROCEED** — no Phase 7.3 hardening pass is required
before moving to the next phase. If a future phase touches
`BookingsTable`/`EditBooking` again, consider folding in the delete-button
visibility polish as a drive-by improvement rather than as a dedicated
pass.
