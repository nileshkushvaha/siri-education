# Phase 6.0 Instructor Availability Foundation

## Decision

Phase 6.0 began as an existing-state audit and implementation plan. After approval, Phase 6.1 implemented the reuse-first availability foundation against the existing booking availability tables.

The implementation reuses and hardens the existing booking availability foundation instead of creating a parallel instructor availability system.

## Phase 6.1 Implementation Summary

Implemented:

- Added timezone and actor metadata to existing `teacher_availability`.
- Added timezone and actor metadata to existing `teacher_unavailability`.
- Kept `teacher_availability` as the recurring weekly availability table.
- Kept `teacher_unavailability` as the time-off / blackout table.
- Did not create duplicate `instructor_availability_rules` or `instructor_time_off` tables.
- Added service-backed writes through:
  - `InstructorAvailabilityService`
  - `InstructorTimeOffService`
- Hardened slot generation so weekly windows expand in the instructor/window timezone before UTC conflict checks.
- Updated Filament availability and leave create/edit/delete actions to call services.
- Updated Filament availability activate/deactivate bulk actions to call services.
- Added instructor frontend availability management page:
  - `GET /dashboard/instructor/availability`
  - weekly window creation, publish/draft toggle, removal
  - time-off creation and removal
- Updated instructor account navigation with Availability.
- Added focused feature coverage for services, timezone slot generation, time-off blocking, and frontend page management.

Not implemented in this pass:

- Date-specific positive availability exceptions table.
- Per-instructor slot settings.
- Student booking UI changes.
- Booking/payment/wallet/meeting/homework/review expansion.

## Prerequisite Gate

Phase 5 Marketplace Discovery Final Audit completed with:

- Score: 95/100
- Decision: SAFE TO PROCEED WITH PHASE 6 PLANNING
- Blocking issues: none
- Marketplace handoff to the existing booking wizard preserves selected instructor context
- No duplicate marketplace, instructor, student, profile, favorite, or subject structures were created
- Availability, wallet, payment, meeting, homework, public reviews, referrals, AI, packages, and subscriptions were not expanded

## Existing-State Summary

### Tables

Existing availability and booking-related tables:

- `teacher_availability`
  - Recurring weekly availability windows.
  - Columns: `id`, `teacher_id`, `day_of_week`, `start_time`, `end_time`, `effective_from`, `effective_until`, `is_active`, timestamps.
  - Has a `start_time < end_time` check constraint.
  - Indexed by `teacher_id`, `day_of_week`, and `is_active`.
- `teacher_unavailability`
  - One-off blackout / leave windows.
  - Columns: `id`, `teacher_id`, `starts_at`, `ends_at`, `reason`, timestamps.
  - Has a `starts_at < ends_at` check constraint.
  - Indexed for overlap lookups.
- `holidays`
  - Organisation-wide non-working dates.
  - Columns: `id`, `date`, `name`, timestamps.
- `booking_types`
  - Contains duration and `buffer_minutes`.
- `bookings`
  - Existing booking lifecycle and conflict source.
- `teacher_subjects`
  - Existing instructor subject compatibility table.
  - Reconciled with `subjects.subject_id` where available.
- `subjects`
  - Academic master data source.
- `user_profiles`
  - Instructor lifecycle, public visibility, profile timezone, teaching preferences, and vacation status through `InstructorStatus`.

### Models

Existing relevant models:

- `TeacherAvailability`
- `TeacherUnavailability`
- `Holiday`
- `Booking`
- `BookingType`
- `TeacherSubject`
- `Subject`
- `User`
- `UserProfile`

`TeacherAvailability` and `TeacherUnavailability` currently use Spatie model activity logging. Phase 6.1 service-level writes should also use `AuditTrailService` for explicit business audit entries, matching project conventions.

### Services And Repositories

Existing services and repositories:

- `AvailabilityService`
  - Generates slots on demand.
  - Applies weekly windows, booking window, holidays, leave, existing bookings with buffer, shared-slot rules, and daily cap.
  - Provides `ensureAvailable()` for single-slot checks.
- `SlotGenerator`
  - Pure interval slicing and conflict math.
  - No persistence and no clock dependency.
- `AvailabilityRepository`
  - Reads weekly windows, holidays, and blackouts.
- `BookingService`
  - Runs validation, host lock, transaction, race-safe availability re-check, booking creation, and booking activity logging.
- `GuestBookingService`
  - Aggregates availability for public booking.
  - Supports locked-instructor booking handoff from marketplace profiles.
- `StudentBookingService`
  - Authenticated student booking layer over the same booking engine.
- `TeacherCandidateRepository`
  - Filters eligible instructors by `TeacherSubject` and bookable `InstructorStatus`.
- `BookingWizardService`
  - Read-side wizard facade for public booking UI.
- `InstructorService`
  - Public marketplace listing/detail service.
  - Uses `teacherAvailability` only as a read-side availability-preview signal.

### Routes

Existing route surfaces:

- Public booking wizard:
  - `GET /book`
  - `GET /instructors/book`
- Guest booking API:
  - `GET /api/v1/guest/availability/dates`
  - `GET /api/v1/guest/availability/slots`
  - `POST /api/v1/guest/bookings`
- Student booking JSON endpoints:
  - `GET /dashboard/bookings/teachers`
  - `GET /dashboard/bookings/slots`
  - `POST /dashboard/bookings`
- Public marketplace:
  - `GET /instructors`
  - `GET /instructors/{user:slug}`

No instructor self-service availability routes were found.

### Views And Admin Surfaces

Existing UI/admin surfaces:

- Public booking wizard view: `resources/views/booking/create.blade.php`
- Livewire booking wizard: `app/Livewire/Frontend/Booking/BookingWizard.php`
- Booking wizard Blade: `resources/views/livewire/frontend/booking/booking-wizard.blade.php`
- Marketplace listing/detail pages show availability-related context but do not edit availability.
- Filament `TeacherAvailabilityResource`
  - Manages weekly windows.
  - Filters selectable teachers to bookable instructor statuses.
  - Generic create/edit pages currently write `TeacherAvailability` records directly.
  - Bulk activate/deactivate currently update rows directly.
- Filament `TeacherLeave` resource
  - Manages `TeacherUnavailability`.
  - Form text says times are UTC.

### Policies And Permissions

Existing policies:

- `TeacherAvailabilityPolicy`
- `TeacherUnavailabilityPolicy`

They use Shield-style permission names such as:

- `ViewAny:TeacherAvailability`
- `Create:TeacherAvailability`
- `Update:TeacherAvailability`
- `Delete:TeacherAvailability`
- `ViewAny:TeacherUnavailability`
- `Create:TeacherUnavailability`
- `Update:TeacherUnavailability`
- `Delete:TeacherUnavailability`

Current policies are admin-permission-oriented. They do not yet express instructor-owner self-service behavior.

### Tests

Existing relevant test coverage includes:

- `tests/Unit/Booking/SlotGeneratorTest.php`
- `tests/Feature/Guest/GuestBookingTest.php`
- `tests/Feature/Guest/BookingWizardLivewireTest.php`
- `tests/Feature/Student/StudentBookingTest.php`
- `tests/Feature/Student/BookingHistoryTest.php`
- `tests/Feature/Booking/BookingAnalyticsTest.php`
- `tests/Feature/Booking/PaymentWorkflowTest.php`
- `tests/Feature/Filament/BookingAdminPanelTest.php`
- `tests/Feature/Instructor/InstructorLifecycleTest.php`
- `tests/Feature/Instructor/MarketplaceDiscoveryFoundationTest.php`
- `tests/Feature/Academic/SubjectTeacherSubjectReconciliationTest.php`

### Docs

Relevant docs:

- `docs/booking.md`
- `docs/architecture/phase-5-marketplace-discovery-foundation.md`
- `docs/audits/phase-5-marketplace-discovery-final-audit.md`
- `docs/audits/phase-4-learning-plan-final-audit.md`
- `docs/audits/phase-2-instructor-onboarding-final-audit.md`

## Duplicate Prevention Decision

Availability-like structures already exist and are in active use by the booking engine.

Do not create these as new primary tables:

- `instructor_availability_rules`
- `instructor_time_off`
- duplicate instructor schedule tables
- duplicate slot tables
- duplicate booking-slot reservation tables

Reuse existing structures:

- `teacher_availability` remains the recurring weekly availability rule table.
- `teacher_unavailability` remains the instructor time-off / blackout table.
- `holidays` remains the organisation-wide non-working-day table.
- `booking_types.buffer_minutes` remains the booking-type buffer source.
- `BookingSettings` remains the global min-notice, max-advance, and daily-cap source.
- `user_profiles.timezone` remains the instructor's default scheduling timezone, while `teacher_availability.timezone` preserves the timezone used by each weekly window.

Naming note: the existing booking module uses `teacher_*` naming. Phase 6.1 should continue that convention for compatibility unless a deliberate rename migration is separately approved. Do not introduce parallel `instructor_*` availability tables while `teacher_availability` remains active.

## Recommended Data Model

### Keep Existing Tables

Keep and enhance:

- `teacher_availability`
- `teacher_unavailability`
- `holidays`

### Deferred Migration: `teacher_availability_exceptions`

Add only if Phase 6.1 needs date-specific available overrides beyond weekly rules and blackout periods.

Purpose:

- Represent one-off date overrides.
- Allow an instructor to open a special date outside normal weekly hours.
- Allow partial-day blocked exceptions without overloading full leave records.

Recommended columns:

- `id`
- `teacher_id`
- `date`
- `type`
  - `available`
  - `unavailable`
  - `blocked`
- `start_time` nullable
- `end_time` nullable
- `timezone`
- `reason` nullable
- `created_by` nullable
- `updated_by` nullable
- timestamps
- optional soft deletes

Constraints:

- FK `teacher_id` to `users.id`, cascade on delete.
- Unique or scoped duplicate guard on `teacher_id`, `date`, `type`, `start_time`, `end_time` where practical.
- `start_time < end_time` when both are present.

Rationale:

- Existing `teacher_unavailability` already covers time off and blocked periods.
- Existing `teacher_availability` already covers weekly recurrence.
- Only positive one-off availability is missing.

### Defer Per-Instructor Slot Settings

Do not add `instructor_slot_settings` unless there is a specific product requirement.

Use existing sources:

- Duration: `booking_types.duration_minutes`
- Buffer: `booking_types.buffer_minutes`
- Min notice: `BookingSettings::min_lead_hours`
- Max advance: `BookingSettings::max_advance_days`
- Daily cap: `BookingSettings::max_daily_bookings_per_teacher`

Future per-instructor settings can be added later after real product need is clear.

### Do Not Store Generated Slots

Do not create a slot table. Generated slots should remain derived on demand from rules, exceptions, time off, holidays, bookings, booking settings, and instructor status.

## Timezone Strategy

Current behavior:

- `AvailabilityService` and `AvailabilityRepository` operate on UTC ranges.
- Returned `TimeSlotData` is converted to `AvailabilityQueryData::timezone`.
- `TeacherAvailabilityForm` describes weekly times as stored in UTC.
- `TeacherLeaveForm` describes leave times as UTC.

Phase 6.1 hardens this behavior as follows:

1. `user_profiles.timezone` remains the default instructor scheduling timezone.
2. `teacher_availability.timezone` stores the timezone used for each weekly window.
3. Instructor UI collects weekly availability as local wall-clock times in the selected timezone.
4. Slot generation expands weekly rules in the row/instructor timezone, then converts candidates to UTC for conflict checks.
5. Returned slots still use `AvailabilityQueryData::timezone`.
6. `teacher_unavailability.starts_at` and `teacher_unavailability.ends_at` are stored in UTC, with `teacher_unavailability.timezone` retained for display and audit context.
7. Carbon timezone conversion handles DST transitions.
8. Future slots are still generated dynamically and never stored.

Resolved hardening item:

- `AvailabilityRepository::windowsFor()` now expands weekly windows in the availability row / instructor timezone and converts candidates to UTC.
- `AvailabilityRepository::windowCovers()` now evaluates the requested slot against the row / instructor timezone before checking day and local wall-clock range.

## Slot Generation Strategy

Reuse `AvailabilityService` and `SlotGenerator` as the core read engine.

Phase 6.1 added a thin instructor-facing service layer around existing primitives:

- `InstructorAvailabilityService`
  - Create/update/delete weekly windows.
  - Validate instructor eligibility.
  - Validate overlap between own weekly windows.
  - Publish/unpublish windows through service methods.
  - Log business activity.
- `InstructorTimeOffService`
  - Create/update/delete `TeacherUnavailability`.
  - Normalize input from instructor timezone to UTC.
  - Validate non-empty ranges.
  - Log business activity.
- `InstructorSlotPreviewService`
  - Deferred. Current UI manages rules/time off; preview can be added later without changing the storage model.

Slot generation order should be:

1. Confirm instructor user is active and has instructor role.
2. Confirm profile is public/bookable where the consuming context requires public slots.
3. Confirm `user_profiles.instructor_status` is in `InstructorStatus::bookable()`.
4. Expand weekly `teacher_availability` windows in instructor timezone.
5. Apply date-specific exceptions if implemented.
6. Apply `teacher_unavailability`.
7. Apply `holidays`.
8. Apply booking window rules from `BookingSettings`.
9. Slice candidates through `SlotGenerator`.
10. Apply existing bookings and buffers through `BookingRepository`.
11. Apply daily cap.
12. Return candidate slots in requested viewer timezone.

Do not create bookings, payment intents, wallet entries, meetings, homework, reviews, or reservations during slot generation.

## Conflict Prevention

Existing booking conflict prevention already includes:

- Availability fast-fail through `TeacherAvailabilityRule`.
- Race-safe `ensureAvailable()` re-check inside `BookingService::request()`.
- Host-level lock through `BookingRepository::withHostLock()`.
- Duplicate booking guard.
- Existing booking overlap checks.
- Booking-type buffer enforcement.
- Blackout and holiday checks.
- Daily cap enforcement.

Phase 6.1 should integrate with this instead of replacing it.

Additional Phase 6.1 guards:

- Reject publishing availability for suspended, rejected, archived, vacation, or otherwise non-bookable instructors.
- Reject overlapping weekly windows for the same instructor and effective date range.
- Reject time off / exception ranges where end is not after start.
- Reject invalid or unsupported timezones.
- Prevent availability previews from exposing private notes.
- Keep `ensureAvailable()` as the final booking truth.

## Instructor UI Scope

Implemented minimal instructor self-service UI:

- Weekly availability editor
  - Add/edit/delete weekly windows.
  - Day of week, start time, end time, effective dates, active toggle.
  - Times displayed in instructor profile timezone.
- Time off / blocked time editor
  - Add/edit/delete blackout periods.
  - Show reason privately to instructor/admin only.
- Date-specific exceptions and slot preview remain deferred.
- Validation and empty states
  - Missing timezone.
  - Non-bookable status cannot publish availability.
  - Overlapping windows.
  - Invalid time range.

Use Livewire if consistent with the existing instructor dashboard pattern. Keep components thin and call services for writes.

Do not build:

- Student slot picker.
- Checkout.
- Booking confirmation.
- Calendar sync.
- Meeting creation.
- Recurring booking creation.
- Packages/subscriptions.

## Admin / Filament Scope

Existing Filament resources remain, with direct-write bypasses reduced:

- Keep `TeacherAvailabilityResource` for managing availability records.
- Keep `TeacherLeave` resource for leave/blackout records.
- Create/edit/delete and activate/deactivate actions route through `InstructorAvailabilityService`.
- Leave create/edit/delete actions route through `InstructorTimeOffService`.
- Keep filters by instructor, day/status, and active state.
- Restrict selectable instructors to active/bookable profiles for publishable availability.
- Add warnings or disabled state for non-bookable instructors.
- Avoid unsafe bulk changes unless service-backed and audited.
- Do not create duplicate `InstructorResource`.

If an availability exception table is approved, create a resource for exception records only. It must manage availability exceptions, not instructor identity.

## Services / Actions Plan

Preferred service set:

- `App\Services\Instructor\InstructorAvailabilityService`
- `App\Services\Instructor\InstructorTimeOffService`
- `App\Services\Instructor\InstructorSlotPreviewService`
- `App\Services\Instructor\InstructorAvailabilityExceptionService` only if exceptions are implemented

Responsibilities:

- Validate instructor ownership and status.
- Validate timezone.
- Normalize local instructor input into stored values.
- Prevent overlapping availability windows.
- Prevent invalid time off ranges.
- Delegate slot math to existing `AvailabilityService` / `SlotGenerator`.
- Use repositories for database queries where query complexity grows.
- Log activity through `AuditTrailService`.

Do not move booking creation or payment behavior into these services.

## Policies

Enhance existing policies or add dedicated ability methods:

- Instructor can view/manage own availability only through frontend self-service.
- Instructor can draft availability setup during onboarding only if product explicitly allows it.
- Instructor can publish availability only when approved/active/bookable.
- Suspended, rejected, archived, and vacation instructors cannot publish availability.
- Admin/manager can manage with Shield-style permissions.
- Public/student users cannot read raw availability rules or private notes.
- Public/student users can read generated slots only through existing/future booking/marketplace services.

Potential permissions:

- `ViewAny:TeacherAvailability`
- `View:TeacherAvailability`
- `Create:TeacherAvailability`
- `Update:TeacherAvailability`
- `Delete:TeacherAvailability`
- `Publish:TeacherAvailability`
- `ViewAny:TeacherUnavailability`
- `View:TeacherUnavailability`
- `Create:TeacherUnavailability`
- `Update:TeacherUnavailability`
- `Delete:TeacherUnavailability`

Keep portal routing decisions out of policies and use `PortalResolver` where navigation branching is needed.

## Activity Logging

Use `AuditTrailService` for service-level business actions.

Log:

- availability rule created
- availability rule updated
- availability rule deleted
- availability published
- availability unpublished
- time off created
- time off updated
- time off deleted
- exception created/updated/deleted if implemented
- slot settings updated if implemented later

Log safe metadata only:

- actor ID
- instructor user ID
- record ID
- old/new status or time range
- reason when supplied

Do not log private notes or sensitive profile data unnecessarily.

## Tests Required

Phase 6.1 added focused tests for:

- service-created timezone-scoped availability
- rejection of published availability for non-bookable instructors
- overlapping active weekly window rejection
- weekly slot expansion in instructor timezone
- local time-off input stored as UTC and blocking generated slots
- instructor frontend page management

Remaining recommended tests:

### Existing Engine Regression

- Existing booking tests still pass.
- Existing marketplace tests still pass.
- Existing instructor onboarding tests still pass.
- Existing subject reconciliation tests still pass.

### Weekly Availability

- Approved/active instructor can create weekly availability through service.
- Suspended/rejected/archived/vacation instructor cannot publish availability.
- Weekly availability rejects invalid time ranges.
- Weekly availability rejects overlapping windows for same instructor and effective range.
- Weekly availability uses `user_profiles.timezone` for local expansion.
- DST transition dates are handled or rejected predictably.

### Time Off / Blackouts

- Instructor can create own blackout/time off.
- Time off blocks generated slots.
- Invalid blackout range is rejected.
- Private reason is not exposed publicly.

### Exceptions If Implemented

- Date-specific available exception can create slots outside weekly rules.
- Date-specific blocked exception removes slots.
- Exceptions override weekly rules in the documented order.
- Exception timezone is respected.

### Slot Generation

- Slot preview does not create bookings.
- Slot preview does not create payments.
- Slot preview does not create wallet entries.
- Slots respect booking type duration.
- Slots respect booking type buffer.
- Slots respect min notice and max advance booking window.
- Slots respect existing bookings.
- Slots respect holidays.
- Slots respect daily cap.
- Non-bookable instructor produces no public bookable slots.

### UI / Livewire

- Instructor availability page loads for eligible instructor.
- Instructor sees missing-timezone message when profile timezone is absent.
- Instructor can add, publish/unpublish, and delete own weekly windows through UI.
- Instructor cannot manage another instructor's availability.
- Instructor can add and delete own time off through UI.
- Slot preview shows generated slots without reserving them. Deferred.

### Admin / Filament

- Permitted admin can view availability resources.
- Non-permitted admin cannot manage availability.
- Admin create/edit actions call services or enforce equivalent policy/service validation.
- Bulk publish/unpublish, if kept, is service-backed and audited.

### Duplicate Prevention

- No `instructors` table.
- No `instructor_profiles` table.
- No duplicate `instructor_availability_rules` table while `teacher_availability` exists.
- No duplicate `instructor_time_off` table while `teacher_unavailability` exists.
- No slot storage table.
- No booking/payment/wallet/meeting/homework/review expansion.

## Documentation Plan

Phase 6.1 updated:

- `docs/booking.md`
  - Document instructor timezone expansion rules.
  - Document service-backed admin and self-service availability management.
- `docs/architecture/phase-6-instructor-availability-foundation.md`
  - Converted this plan into implementation record with actual files changed.
- Future audit doc after implementation:
  - `docs/audits/phase-6-instructor-availability-foundation-audit.md`

## Exact Implementation Order

1. Reconfirm Phase 5.3 final audit gate.
2. Add focused tests for existing duplicate-prevention and current availability behavior.
3. Add service layer for existing `teacher_availability` writes.
4. Add service layer for existing `teacher_unavailability` writes.
5. Harden timezone expansion in `AvailabilityRepository` / availability generation.
6. Add `teacher_availability_exceptions` only if approved for one-off available overrides.
7. Add exception model/service/policy/resource only if the table is approved.
8. Add instructor self-service Livewire page.
9. Route instructor UI writes through services.
10. Convert Filament create/edit/bulk actions to service-backed actions or harden them with equivalent service validation.
11. Add slot preview UI using existing `AvailabilityService`.
12. Add activity logging through `AuditTrailService`.
13. Add permissions/seeder updates if new abilities are introduced.
14. Update docs.
15. Run full required command set.

## Out Of Scope

Phase 6.1 must not build:

- booking engine rewrite
- checkout
- payment
- wallet
- meeting creation
- recurring booking creation
- demo booking finalization
- student booking UI rewrite
- packages/subscriptions
- external calendar sync
- AI scheduling
- homework expansion
- review expansion
- slot reservation table

## Phase 6.1 Completion Decision

Phase 6.1 implementation is ready for audit after the required verification commands pass.

Recommended Phase 6.1 scope:

- Reuse existing `teacher_availability` and `teacher_unavailability`.
- Harden timezone correctness.
- Add instructor self-service availability management.
- Add optional `teacher_availability_exceptions` only if one-off positive availability is required.
- Keep generated slots dynamic.
- Keep booking creation and conflict authority inside the existing booking engine.

## Phase 6.2 Strict Audit

Phase 6.2 (`docs/audits/phase-6-instructor-availability-foundation-audit.md`) scored the
foundation 88/100, **PROCEED WITH CAUTION** — no blocking issues, but four
hardening gaps before Phase 7:

1. Filament table row/bulk delete for Teacher Availability and Teacher
   Leave bypassed the availability/time-off services.
2. Services did not centrally assert actor ownership or admin
   permission — enforcement lived only in callers/Filament policies.
3. Cross-user, admin-permission, service-backed-Filament-action,
   invalid-range, and UI delete/toggle test coverage was incomplete.
4. Missing `user_profiles.timezone` silently fell back to the app
   timezone with no UI warning.

## Phase 6.3 Availability Admin, Policy & Test Hardening

Closes all four Phase 6.2 gaps. No new tables, no booking/payment/
wallet/meeting/homework/review expansion, no student booking UI.

### 1. Service-backed Filament delete/bulk delete

`TeacherAvailabilityTable` and `TeacherLeaveTable` no longer use a bare
`DeleteAction::make()` / `DeleteBulkAction::make()`. Both now:

- `->authorize()` (row) / `->authorizeIndividualRecords('delete')`
  (bulk) against the resource's `delete` policy ability.
- `->action()` a closure that calls
  `InstructorAvailabilityService::delete()` /
  `InstructorTimeOffService::delete()` — so audit logging, and the new
  service-level ownership/permission guard (below), always run.
- Catch `ValidationException` / `AuthorizationException` and surface a
  Filament danger notification instead of a raw exception; bulk
  actions report a partial-failure count if some records fail.

### 2. Service-level ownership and admin permission guards

`InstructorAvailabilityService` and `InstructorTimeOffService` gained
`assertCanCreate()` / `assertCanManage()` / `isSelfService()` private
guards, called at the top of every `create`/`update`/`delete`. An
actor may proceed only if:

- **Self-service**: `actor->id === teacher_id` and
  `actor->hasRole('instructor')` — an instructor managing their own
  record, matching the existing Livewire scoping
  (`where('teacher_id', auth()->id())`).
- **Admin**: `actor->can($ability, $record)` (or the model class for
  `create`) resolves true via `TeacherAvailabilityPolicy` /
  `TeacherUnavailabilityPolicy` — i.e. the actor holds the matching
  Shield permission.

Anything else — cross-instructor access, a student, an unpermitted
manager, or an `update()` call that tries to reassign `teacher_id` to
someone the actor doesn't own/administer — throws
`Illuminate\Auth\Access\AuthorizationException`. This is defense in
depth: Filament policies and Livewire's `teacher_id` scoping already
blocked most of these paths, but the service itself is now the
authoritative boundary regardless of caller.

Two more service-level validations were added in the same pass:

- `assertValidEffectiveRange()` — `effective_until` before
  `effective_from` is rejected.
- `assertTimezoneResolved()` — publishing (`is_active = true`) is
  rejected when neither the call nor the instructor profile supplies
  a timezone (see the timezone UX note below); draft creation is
  unaffected.

### 3. Missing timezone UX

`AvailabilityManager` (`app/Livewire/Frontend/Instructor/AvailabilityManager.php`)
exposes `hasProfileTimezone` and leaves `$timezone` blank (rather than
defaulting to `config('app.timezone')`) when
`user_profiles.timezone` is empty. The Blade view
(`resources/views/livewire/frontend/instructor/availability-manager.blade.php`)
shows a warning banner linking to `profile.show` in that state, and
the timezone `<select>` gets an empty placeholder option so the
instructor must explicitly choose one — Livewire's `required` rule on
`timezone` then blocks submission until they do. The service-level
`assertTimezoneResolved()` guard backs this up for every entry point
(Filament included), so publishing can never silently use the app
timezone. Drafts (`is_active = false`) may still be created without a
timezone choice, per the documented exception in `docs/booking.md`.

### 4–8. Test coverage

`tests/Feature/Instructor/InstructorAvailabilityHardeningTest.php`
(34 tests) adds: invalid time/timezone/effective-range rejection;
cross-instructor denial at the service and Livewire layers; permitted
vs. non-permitted admin create/update/delete for both services;
Filament row/bulk delete proven service-backed (audit log assertion)
and permission-gated (`assertTableActionHidden`); frontend toggle/
delete/create-time-off/validation/missing-timezone-warning coverage;
a no-out-of-scope-record-creation test (bookings, homework
assignments, learning-plan reviews unchanged; no wallet/payment/
meeting/reservation/slot tables exist); and a dynamically-computed DST
spring-forward test (see `docs/booking.md`). The pre-existing
`InstructorAvailabilityServiceTest` was updated to use an authorized
actor (self-service instructor or a permitted admin) now that the
service enforces authorization — it previously used an arbitrary
unrelated user, which is exactly the gap this phase closes.

### Files changed

| File | Change |
|---|---|
| `app/Services/Instructor/InstructorAvailabilityService.php` | Ownership/admin guards, effective-range check, timezone-resolved guard. |
| `app/Services/Instructor/InstructorTimeOffService.php` | Ownership/admin guards. |
| `app/Filament/Resources/TeacherAvailability/Tables/TeacherAvailabilityTable.php` | Service-backed row/bulk delete. |
| `app/Filament/Resources/TeacherLeave/Tables/TeacherLeaveTable.php` | Service-backed row/bulk delete. |
| `app/Livewire/Frontend/Instructor/AvailabilityManager.php` | `hasProfileTimezone`, blank timezone default when missing. |
| `resources/views/livewire/frontend/instructor/availability-manager.blade.php` | Warning banner, blank timezone placeholder option. |
| `tests/Feature/Instructor/InstructorAvailabilityServiceTest.php` | Actors updated to satisfy the new authorization guard. |
| `tests/Feature/Instructor/InstructorAvailabilityHardeningTest.php` | New — see above. |
| `docs/booking.md` | DST note, timezone-UX note, service-backed delete/bulk-delete note, ownership-guard note. |
| `docs/architecture/phase-6-instructor-availability-foundation.md` | This section. |

No migrations were added — all four gaps were closable in the
existing service/Filament/Livewire layer without schema changes.

### Remaining gaps after Phase 6.3

- Date-specific positive availability exceptions
  (`teacher_availability_exceptions`) remain deferred, unchanged from
  Phase 6.1 — still not required by any approved product requirement.
- `InstructorSlotPreviewService` remains deferred.
- DST coverage is a single dynamic spring-forward test on one
  Southern Hemisphere timezone; it is not an exhaustive matrix of
  every IANA timezone's transition rules. Documented as non-blocking:
  the underlying mechanism (Carbon per-instant offset conversion) is
  timezone-agnostic, so this is considered representative coverage
  rather than a gap requiring further tests before Phase 7.

### Phase 6.3 Completion Decision

All four Phase 6.2 hardening items are closed: Filament delete/bulk
delete no longer bypass services; services centrally assert
ownership/admin permission; the missing-timezone UX warns and blocks
publish; and the required test categories (invalid ranges, cross-user
denial, admin permissions, Filament service-backed actions, frontend
UI operations, out-of-scope record creation, DST) are covered. Ready
for a Phase 6 final audit; see the top-level response for command
output confirming `php artisan test`, `migrate:status`, `route:list`,
`pint --test`, and `composer validate` all pass with no duplicate
availability/booking/payment/wallet/meeting/homework/review/instructor/
profile structures introduced and no booking/payment/wallet/meeting/
homework/review expansion.
