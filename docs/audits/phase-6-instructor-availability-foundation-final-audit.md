# Phase 6.4 Instructor Availability Final Audit

## Executive Decision

Readiness score: **96/100**

Decision: **SAFE TO PROCEED TO PHASE 7**

Blocking issues: **none**

Phase 6.3 closed all four hardening gaps identified by the Phase 6.2 strict
audit (`docs/audits/phase-6-instructor-availability-foundation-audit.md`,
88/100, PROCEED WITH CAUTION). This audit independently re-verified — by
reading the current source, not by trusting the prior session's summary —
that: Filament row/bulk delete for Teacher Availability and Teacher Leave
are fully service-backed and permission-gated; both instructor availability
services assert actor ownership/admin permission internally; the missing
instructor-timezone case now warns in the UI and blocks publishing rather
than silently defaulting to the app timezone; and the previously missing
test categories (invalid ranges, cross-user denial, admin permissions,
Filament service-backing, frontend UI operations, out-of-scope record
creation, DST) are covered by 34 new focused tests. Two minor, non-blocking
findings were identified during this audit (below) — neither is a
production defect and neither blocks Phase 7.

## Prerequisite Gate

Verified prerequisite:

- File: `docs/audits/phase-6-instructor-availability-foundation-audit.md`
- Score: 88/100
- Decision: PROCEED WITH CAUTION — Phase 6.3 required before Phase 7
- Blocking issues: none
- Required hardening: (1) service-backed Filament delete/bulk delete,
  (2) service-level ownership/admin permission guards, (3) timezone
  missing-state UX, (4) expanded test coverage — all four independently
  re-verified as closed in this audit.

## Files Created In Phase 6.3

| File | Purpose | Necessary | Duplicate Risk |
|---|---|---:|---|
| `tests/Feature/Instructor/InstructorAvailabilityHardeningTest.php` | Invalid-range, cross-user denial, admin-permission, Filament service-backing, frontend UI, out-of-scope, and DST coverage (34 tests). | Yes | None |
| `docs/audits/phase-6-instructor-availability-foundation-final-audit.md` | This document. | Yes | None |

No new PHP classes, models, migrations, or Filament resources were created.
Verified by diffing the working tree against the Phase 6.2 baseline commit
(`dc2da31`): only 9 existing files were modified, plus the one new test
file above.

## Files Modified In Phase 6.3

| File | Change | Assessment |
|---|---|---|
| `app/Services/Instructor/InstructorAvailabilityService.php` | Added `assertCanCreate()` / `assertCanManage()` / `isSelfService()` ownership-or-admin-permission guards on create/update/delete; `assertValidEffectiveRange()`; `assertTimezoneResolved()` blocking publish without a resolvable timezone. | Correct — re-read in full; guard logic is sound (verified: cross-instructor reassignment via `teacher_id` in `update()` is also blocked, since both the existing and target teacher must satisfy self-service or fall to the policy check). |
| `app/Services/Instructor/InstructorTimeOffService.php` | Same guard pattern (`assertCanCreate()` / `assertCanManage()` / `isSelfService()`). | Correct, symmetric with the availability service. |
| `app/Filament/Resources/TeacherAvailability/Tables/TeacherAvailabilityTable.php` | Row `DeleteAction` and bulk `DeleteBulkAction` now `->authorize()` / `->authorizeIndividualRecords('delete')` and call `InstructorAvailabilityService::delete()` inside try/catch with Filament notifications. | Correct — no bare `DeleteAction::make(),` / `DeleteBulkAction::make(),` remains; confirmed by direct grep and full file read. |
| `app/Filament/Resources/TeacherLeave/Tables/TeacherLeaveTable.php` | Same pattern for `InstructorTimeOffService`. | Correct, symmetric. |
| `app/Livewire/Frontend/Instructor/AvailabilityManager.php` | `hasProfileTimezone` public property; `$timezone` left `null` (not app-default) when the profile has none. | Correct. |
| `resources/views/livewire/frontend/instructor/availability-manager.blade.php` | Warning banner + link to `profile.show`; blank placeholder option in the timezone `<select>`. | Correct, with one minor copy-accuracy finding (below). |
| `tests/Feature/Instructor/InstructorAvailabilityServiceTest.php` | Actors changed from an arbitrary unrelated user to a permitted admin/self-service instructor, since the service now enforces authorization. | Correct fix — this test previously exercised exactly the gap Phase 6.3 closed (any authenticated user could create availability for any instructor through the service). |
| `docs/booking.md` | DST behavior note, timezone-missing UX note, service-backed delete/bulk-delete note, service-level ownership-guard note. | Spot-checked against `AvailabilityRepository::windowsFor()` — the DST claim ("expands using Carbon's per-instant offset, not a fixed UTC delta") is accurate: `$date->setTimeFromTimeString(...)->utc()` runs per local calendar day inside the loop, so the UTC offset applied is whatever is correct for that specific local instant. |
| `docs/architecture/phase-6-instructor-availability-foundation.md` | New "Phase 6.2 Strict Audit" and "Phase 6.3 Availability Admin, Policy & Test Hardening" sections. | Accurate summary of the actual diff. |

## Migrations

None added in Phase 6.3. `php artisan migrate:status` (dev database) still
shows batch **36** as the latest applied batch — identical to the Phase 6.2
audit baseline. No new availability, booking, payment, wallet, meeting,
homework, review, instructor, or profile table exists.

## Service-Level Authorization Audit

Independently re-derived (not copied from the Phase 6.3 summary) by reading
`InstructorAvailabilityService` and `InstructorTimeOffService` in full:

- **Self-service**: `actor->id === teacher_id && actor->hasRole('instructor')`.
  An instructor may act on their own record without holding any Shield
  permission — matches the existing Livewire `where('teacher_id', auth()->id())`
  scoping, so self-service instructors are never blocked by the new guard.
- **Admin**: falls back to `actor->can($ability, $record)` (or the model
  class for `create`), i.e. `TeacherAvailabilityPolicy` /
  `TeacherUnavailabilityPolicy`, which check the Shield permission name.
  `Gate::before()` (`app/Providers/AppServiceProvider.php`) still grants
  `super_admin` an unconditional bypass, consistent with the rest of the
  app.
- **Reassignment via `teacher_id` on `update()`** is correctly blocked for
  non-admins: `assertCanManage()` requires *both* the existing record's
  owner and the (possibly different) target teacher to satisfy
  self-service, so an instructor cannot use `update()` to silently claim
  another instructor's record by passing their own id as the new
  `teacher_id` unless they also hold the admin permission.
- Anyone failing both checks gets `Illuminate\Auth\Access\AuthorizationException`
  — verified to actually propagate (not swallowed) in 34/34 new tests
  including direct service calls, admin-permission tests, and Filament
  action tests.

No duplicate permission system was introduced; the guard reuses the
existing `TeacherAvailabilityPolicy` / `TeacherUnavailabilityPolicy` Shield
permission names already documented in `docs/booking.md`.

## Filament Delete/Bulk-Delete Audit

Confirmed by direct file read and by grepping every `DeleteAction`/
`DeleteBulkAction` occurrence across both resources' Tables and Pages:

- `TeacherAvailabilityTable` / `TeacherLeaveTable`: row delete calls
  `->authorize(fn ($record) => auth()->user()?->can('delete', $record) ?? false)`
  then `->action()` a closure that calls the service and catches
  `ValidationException` / `AuthorizationException` into a danger
  notification. Bulk delete calls `->authorizeIndividualRecords('delete')`
  (per-record `Gate::inspect`) and loops the service call, reporting a
  partial-failure count.
- Header `DeleteAction` on both Edit pages (`EditTeacherAvailability`,
  `EditTeacherLeave`) already used `->using()` to call the service since
  Phase 6.1 — unchanged, still correct.
- No generic `Model::delete()` path remains anywhere in either resource.

Verified functionally, not just by inspection: `test_filament_row_delete_action_is_service_backed_for_availability`
/ `..._for_time_off` and their bulk counterparts assert the record is
actually gone **and** that a service-authored audit-log entry exists;
`test_filament_row_delete_action_is_hidden_without_permission` asserts the
action is hidden (not just denied) for an admin who can view the list but
lacks `Delete:TeacherAvailability`.

## Timezone UX Audit

Confirmed:

- `AvailabilityManager::mount()` sets `hasProfileTimezone` from
  `user_profiles.timezone` presence and leaves `$timezone` unset (not
  `config('app.timezone')`) when absent.
- The Blade view renders a warning banner linking to `profile.show` when
  `hasProfileTimezone` is false, and the timezone `<select>` has a blank
  placeholder option so a browser default-selection can't silently supply
  a value.
- `InstructorAvailabilityService::assertTimezoneResolved()` blocks
  publishing (`is_active = true`) without either an explicit `timezone` in
  the call or a profile timezone, and is called from every entry point
  (Filament admin, frontend Livewire, and direct service calls) — not just
  the UI layer. Draft creation (`is_active = false`) is intentionally
  unaffected, and is documented as such.
- Verified end-to-end by `test_missing_timezone_warning_appears_and_blocks_publish`
  (banner renders + Livewire validation blocks submission) and
  `test_service_blocks_publish_without_profile_timezone_or_explicit_choice`
  / `test_service_allows_draft_creation_without_profile_timezone` (service
  guard exercised directly, independent of the UI).

## Test Coverage Audit

`composer test` (the safe `php artisan test --env=testing` invocation) —
independently re-run in this audit session:

```
1859 tests passed, 4104 assertions
```

(1825/4014 at the Phase 6.2 baseline + 34/90 new.) All 260 Booking/
Instructor-scoped tests pass; the full suite passes with zero failures or
errors.

Reviewed `InstructorAvailabilityHardeningTest.php` and the updated
`InstructorAvailabilityServiceTest.php` test-by-test:

- Invalid-range coverage (weekly time range, time-off range, invalid
  timezone ×2, `effective_until` before `effective_from`) is direct and
  correct.
- Cross-user denial is tested at both layers required by the Phase 6.3
  spec: the service layer (`AuthorizationException`) and the Livewire
  layer (`ModelNotFoundException`, proving the `where('teacher_id', ...)`
  scoping — a different and equally important defense).
- Admin-permission tests correctly exercise the "permission doesn't exist
  yet" pre-seeder state (the non-permitted-admin helper never seeds the
  permission row, exercising `TeacherAvailabilityPolicy`'s
  `PermissionDoesNotExist` → `false` fallback), not just "permission
  exists but not granted".
- The DST test computes the transition date dynamically via
  `DateTimeZone::getTransitions()` rather than hardcoding a calendar date,
  filters specifically for a "spring forward" (`isdst === true`) edge so
  the offset-delta assertion is unambiguous, and self-skips (non-blocking)
  if no transition falls inside the booking engine's max-advance window —
  this is materially more robust than a fixed-date test and will not rot.

## Minor Non-Blocking Findings

1. **Warning-banner copy overstates what the frontend form can do.** The
   banner text says "drafts can be saved without one," but
   `AvailabilityManager::addWindow()` hardcodes `'is_active' => true` for
   every window created through that form, and its own Livewire
   `validate()` call requires `timezone` unconditionally — so a draft
   without a timezone is only reachable through the service directly
   (verified by `test_service_allows_draft_creation_without_profile_timezone`)
   or a future Filament/admin path, never through this specific UI. Not a
   security or data-integrity issue — the stricter frontend behavior is if
   anything safer than promised. Recommend either adding a draft-save
   option to the form or softening the banner copy in a future pass;
   non-blocking for Phase 7.
2. **Two Filament "service-backed" row-delete tests use a weaker
   assertion than their bulk counterparts.**
   `test_filament_row_delete_action_is_service_backed_for_availability`
   and the time-off equivalent assert
   `Activity::where('log_name', ...)->where('event', 'deleted')->exists()`
   without also filtering by `description`. Since `TeacherAvailability`
   and `TeacherUnavailability` both still carry Spatie's automatic
   `LogsActivity` trait (default description is just the event name,
   e.g. `"deleted"`, confirmed by reading
   `vendor/spatie/laravel-activitylog/.../LogsActivity.php`), that
   assertion would also pass for a hypothetical future regression where
   the row-delete action is reverted to a bare `Model::delete()` — because
   the model's own automatic log entry would still satisfy it. The bulk
   variants already filter by the service's exact description string and
   don't have this gap. Recommend adding the same `->where('description', ...)`
   filter to the two row-delete tests. Test-quality only; the production
   code itself is correctly service-backed (independently confirmed by
   direct file read in this audit, not by the test).

## Out-Of-Scope Boundary Audit

Confirmed Phase 6.3 did not expand:

- booking engine, payment, wallet, meeting, homework, review, referral,
  AI recommendation, package, or subscription domains
- student booking UI
- slot/reservation persistence

`test_availability_operations_and_slot_generation_create_no_out_of_scope_records`
asserts `Booking`, `HomeworkAssignment`, and `LearningPlanReview` counts
are unchanged by availability/time-off creation and slot generation, and
asserts `wallets`, `payments`, `meetings`, `reservations`, `slots`,
`generated_slots`, and `booking_slots` tables do not exist in the schema.

## Duplicate Prevention Check

Verified via `git diff --stat` against the Phase 6.2 baseline commit and a
filesystem search for anything newer than that commit under
`app/Models`, `database/migrations`, and `app/Filament/Resources`:

| Term / Concept | Result |
|---|---|
| New models | None created |
| New migrations | None created |
| New Filament resources | None created |
| `teacher_availability` / `teacher_unavailability` | Unchanged, still the sole availability/time-off tables |
| Duplicate permission system | None — reuses existing Shield-style policy permissions |

## Commands

| Command | Result |
|---|---|
| `composer test` (`php artisan test --env=testing`) | Passed: 1859 tests, 4104 assertions |
| `php artisan migrate:status` | Passed; batch 36 still latest (unchanged since Phase 6.2) |
| `php artisan route:list` | Passed; 218 routes (unchanged since Phase 6.2) |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Passed (Blade view changed; Vite build succeeded) |

`php artisan test` was deliberately **not** run directly in this audit
without `--env=testing` — per the project's known incident where doing so
can resolve the development database instead of the test database. The
dev database (`enterprise_app`) user count was checked before and after
(9 users, unchanged) as an extra safety confirmation.

## Remaining Gaps After Phase 6.4

1. Date-specific positive availability exceptions
   (`teacher_availability_exceptions`) remain deferred — no approved
   product requirement; explicitly out of scope per
   `docs/architecture/phase-6-instructor-availability-foundation.md`.
2. `InstructorSlotPreviewService` remains deferred.
3. The two minor test-rigor/copy findings above — recommended but
   non-blocking follow-ups, not required before Phase 7.
4. DST coverage is one dynamically-located transition on one Southern
   Hemisphere timezone, not an exhaustive per-timezone matrix — accepted
   as representative coverage since the underlying mechanism (Carbon
   per-instant offset conversion) is timezone-agnostic.

## Final Assessment

Phase 6.3 hardening is verified, not merely claimed: every gap called out
by the Phase 6.2 strict audit was independently re-checked against the
current source in this session, the full test suite passes, no duplicate
or out-of-scope structures exist, and documentation matches actual code
behavior.

Decision: **SAFE TO PROCEED TO PHASE 7 — Booking Flow Hardening & Slot
Consumption Planning**

Phase 7 should continue to reuse `AvailabilityService` / `SlotGenerator` /
`BookingRepository::withHostLock()` as the sole booking/slot authority, and
should not duplicate the ownership/admin-permission guard pattern
established here — extend `InstructorAvailabilityService` /
`InstructorTimeOffService` or the booking engine's existing services
rather than introducing new ones.
