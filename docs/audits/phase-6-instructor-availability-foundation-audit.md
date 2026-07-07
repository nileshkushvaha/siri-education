# Phase 6.2 Strict Instructor Availability Foundation Audit

## Executive Decision

Readiness score: **88/100**

Decision: **PROCEED WITH CAUTION**

Blocking issues: **none**

Phase 6.1 establishes the instructor availability foundation on the existing booking availability tables without creating duplicate availability, booking, wallet, payment, meeting, homework, review, instructor, or profile structures. Weekly availability is stored on `teacher_availability`, time off / blackout periods are stored on `teacher_unavailability`, slot generation remains dynamic, and the existing booking engine still performs the final availability check before booking creation.

The implementation is not marked **SAFE TO PROCEED TO PHASE 7** because the audit found service-bypass and coverage gaps that should be hardened before slot consumption planning:

1. Filament table row delete and bulk delete actions for Teacher Availability and Teacher Leave still use generic delete actions instead of the availability/time-off services.
2. Ownership and permission checks are enforced by callers and Filament policies, but not centrally inside the services.
3. Cross-user Livewire denial, admin permission, Filament service-backed action, invalid time range, delete/toggle UI, and out-of-scope record-creation tests are incomplete.
4. Missing instructor timezone is silently defaulted to the application timezone instead of surfacing a clear UI warning.

Recommended next step: **Phase 6.3 - Availability Admin, Policy & Test Hardening**. Do not start Phase 7 until these hardening items are closed.

## Files Created In Phase 6.1

| File | Purpose | Necessary | Duplicate Risk |
|---|---|---:|---|
| `app/Http/Controllers/Instructor/InstructorAvailabilityController.php` | Thin authenticated instructor availability page endpoint. | Yes | Low |
| `app/Livewire/Frontend/Instructor/AvailabilityManager.php` | Instructor self-service weekly availability and time-off UI state/actions. | Yes | Low |
| `app/Services/Instructor/InstructorAvailabilityService.php` | Service-backed weekly availability creation, update, publish/unpublish, delete, validation, and audit logging. | Yes | Low |
| `app/Services/Instructor/InstructorTimeOffService.php` | Service-backed time off / blackout creation, update, delete, timezone conversion, and audit logging. | Yes | Low |
| `database/migrations/2026_07_14_100000_add_timezone_and_actor_fields_to_teacher_availability_tables.php` | Adds timezone and actor metadata to existing availability tables. | Yes | None; additive to existing tables |
| `resources/views/instructor/availability/index.blade.php` | Instructor availability page shell using existing account layout. | Yes | Low |
| `resources/views/livewire/frontend/instructor/availability-manager.blade.php` | Instructor availability manager UI. | Yes | Low |
| `tests/Feature/Instructor/InstructorAvailabilityServiceTest.php` | Coverage for availability service, slot generation, time off, and frontend page access. | Yes | None |
| `docs/architecture/phase-6-instructor-availability-foundation.md` | Architecture record for Phase 6. | Yes | None |

## Files Modified

| File | Change | Backward-Compatible | Audit Notes |
|---|---|---:|---|
| `app/Booking/Repositories/AvailabilityRepository.php` | Expands weekly windows in instructor timezone and converts candidates to UTC. | Yes | Booking compatibility preserved; DST edge tests are still limited. |
| `app/Models/TeacherAvailability.php` | Added timezone/actor fillables, relationships, and activity attributes. | Yes | Existing table reused. |
| `app/Models/TeacherUnavailability.php` | Added timezone/actor fillables, relationships, and activity attributes. | Yes | Existing table reused. |
| `app/Filament/Resources/TeacherAvailability/Pages/CreateTeacherAvailability.php` | Create now delegates to `InstructorAvailabilityService`. | Yes | Service-backed. |
| `app/Filament/Resources/TeacherAvailability/Pages/EditTeacherAvailability.php` | Edit and header delete delegate to `InstructorAvailabilityService`. | Yes | Service-backed. |
| `app/Filament/Resources/TeacherAvailability/Schemas/TeacherAvailabilityForm.php` | Adds timezone field and local-time help text. | Yes | Good. |
| `app/Filament/Resources/TeacherAvailability/Tables/TeacherAvailabilityTable.php` | Adds timezone column and service-backed activate/deactivate bulk actions. | Partial | Row delete and bulk delete still bypass service. |
| `app/Filament/Resources/TeacherLeave/Pages/CreateTeacherLeave.php` | Create now delegates to `InstructorTimeOffService`. | Yes | Service-backed. |
| `app/Filament/Resources/TeacherLeave/Pages/EditTeacherLeave.php` | Edit and header delete delegate to `InstructorTimeOffService`. | Yes | Service-backed. |
| `app/Filament/Resources/TeacherLeave/Schemas/TeacherLeaveForm.php` | Adds timezone field and local-time help text. | Yes | Good. |
| `app/Filament/Resources/TeacherLeave/Tables/TeacherLeaveTable.php` | Adds timezone column/export field. | Partial | Row delete and bulk delete still bypass service. |
| `app/Providers/FrontendServiceProvider.php` | Includes instructor views in frontend account view composers. | Yes | Required for instructor account layout. |
| `app/Services/Account/AccountMenuService.php` | Adds instructor Availability navigation item. | Yes | Uses existing route. |
| `routes/web.php` | Adds `/dashboard/instructor/availability`. | Yes | Uses existing dashboard middleware stack. |
| `database/factories/TeacherAvailabilityFactory.php` | Defaults timezone to `UTC`. | Yes | Test support. |
| `database/factories/TeacherUnavailabilityFactory.php` | Defaults timezone to `UTC`. | Yes | Test support. |
| `docs/booking.md` | Documents timezone-aware availability and service-backed admin operations. | Yes | Needs one correction after hardening generic delete actions. |

Adjacent marketplace/booking-handoff files were already modified in the working tree from Phase 5 activity. They were not part of this audit and were not reverted.

## Migrations

### `2026_07_14_100000_add_timezone_and_actor_fields_to_teacher_availability_tables.php`

- Existing table: `teacher_availability`
- Adds: `timezone`, `created_by`, `updated_by`
- Existing table: `teacher_unavailability`
- Adds: `timezone`, `created_by`, `updated_by`
- FK behavior: `created_by` / `updated_by` reference `users.id` and null on delete.
- Backfill: timezone is populated from `user_profiles.timezone`, falling back to `config('app.timezone')`.
- Rollback: drops foreign keys and added columns.
- Risk: low; nullable/additive fields only.
- Migration status: applied in batch **36**.

No new availability rule, slot, booking, reservation, payment, wallet, meeting, homework, review, instructor, or profile table was created.

## Booking Compatibility

Confirmed:

- `AvailabilityService::slots()` still derives slots dynamically.
- `SlotGenerator` remains the pure interval component and does not persist generated slots.
- `AvailabilityRepository::windowsFor()` now expands weekly windows in the instructor timezone, then returns candidate instants in the requested output timezone.
- `AvailabilityRepository::windowCovers()` converts requested UTC slots into local instructor time before checking day/effective-date/time coverage.
- `teacher_unavailability` still blocks slots before booking.
- Existing booking flows still call the booking service and re-check availability before creation/reschedule.
- Full test suite passed after Phase 6.1.

Risk:

- `windowCovers()` has a conservative UTC day-span pre-check before local timezone conversion. Normal same-day session behavior is covered, but DST/edge-day coverage should be added before heavier booking expansion.

## Timezone Audit

Confirmed:

- Weekly availability stores a timezone.
- Time off stores UTC timestamps and retains the source timezone for display/audit context.
- Invalid timezone values are rejected by the services.
- The Livewire UI defaults timezone from `user_profiles.timezone`, then application timezone.
- Slot generation returns slots in the query timezone while respecting instructor-local weekly windows.
- Time off tests prove local input is converted to UTC and blocks generated slots.

Gap:

- If an instructor profile has no timezone, the UI silently uses the application timezone. This is functional but weak UX. Phase 6.3 should show a clear warning and link to profile timezone settings.

## Weekly Availability Audit

Confirmed:

- Instructors can create weekly windows from the frontend.
- Non-bookable instructors cannot publish active availability.
- Active overlapping windows are rejected.
- Windows can be toggled active/inactive through the frontend component and service.
- Windows can be deleted through the frontend component and service.
- Admin create/edit/header-delete paths use `InstructorAvailabilityService`.
- Admin activate/deactivate bulk actions use `InstructorAvailabilityService`.

Gaps:

- Filament table row delete and bulk delete bypass `InstructorAvailabilityService`.
- There is no direct cross-user Livewire test proving one instructor cannot modify another instructor's window.
- Invalid time range validation exists in service but lacks focused test coverage.

## Time Off / Blackout Audit

Confirmed:

- Instructors can add time off from the frontend.
- Time off is stored in UTC with source timezone retained.
- Time off blocks generated slots.
- Delete is available in the frontend component and calls `InstructorTimeOffService`.
- Admin create/edit/header-delete paths use `InstructorTimeOffService`.
- Service audit logging records `reason_present` rather than logging raw reason text.

Gaps:

- Filament table row delete and bulk delete bypass `InstructorTimeOffService`.
- Frontend time-off delete is not directly covered by a Livewire test.
- Direct cross-user time-off access tests are missing.

## Slot Generation Audit

Confirmed:

- Slots are generated on demand; no generated slot rows are persisted.
- Slot generation respects:
  - weekly windows
  - instructor timezone
  - requested output timezone
  - time off / blackout periods
  - booking settings lead/advance limits
  - existing bookings and buffers
  - daily booking caps
- Phase 6.1 did not create booking, payment, wallet, meeting, homework, or review records.

Gap:

- There is no explicit Phase 6 test asserting slot generation creates no booking/payment/wallet/homework records, though the implementation and regression suite support that behavior.

## Instructor Frontend UI Audit

Confirmed:

- Route exists: `GET /dashboard/instructor/availability`.
- Route uses the existing dashboard middleware group.
- Controller and Livewire component both restrict access to instructor users.
- UI supports weekly windows and time off from one page.
- Validation errors are surfaced in the page.
- Student/public users are not intended to manage instructor availability.

Gaps:

- Missing timezone warning is not shown.
- UI tests cover page access and create behavior, but not toggle/delete/time-off removal.

## Admin / Filament Audit

Confirmed:

- No duplicate availability Filament resource was created.
- Existing Teacher Availability and Teacher Leave resources were reused.
- Create/edit pages delegate to services.
- Header delete actions on edit pages delegate to services.
- Availability activate/deactivate bulk actions delegate to service.
- Tables include timezone visibility.

Audit issue:

- `TeacherAvailabilityTable` still includes generic `DeleteAction::make()` and `DeleteBulkAction::make()`.
- `TeacherLeaveTable` still includes generic `DeleteAction::make()` and `DeleteBulkAction::make()`.
- These paths can bypass service validation and audit logging.

This is the strongest reason for **PROCEED WITH CAUTION**.

## Policies And Permissions Audit

Confirmed:

- Existing Filament resources remain policy-controlled.
- Frontend access is protected by route middleware and instructor role checks.
- Frontend Livewire actions scope database lookups by `teacher_id = auth()->id()`.

Gaps:

- Availability/time-off services do not centrally assert that the actor owns the teacher record or has admin permission.
- Admin permission tests for availability/time-off actions are incomplete.
- Cross-user frontend denial tests are incomplete.

## Activity Logging Audit

Confirmed:

- Availability create/update/delete are logged through `AuditTrailService`.
- Time off create/update/delete are logged through `AuditTrailService`.
- Time off audit properties avoid logging raw reason text.
- Publish/unpublish runs through update logging with previous/current values.

Gap:

- Generic Filament table delete/bulk delete can bypass the service audit trail.

## Out Of Scope Audit

Confirmed not expanded in Phase 6.1:

- Booking engine behavior beyond reading timezone-aware availability.
- Availability slot persistence.
- Recurring lesson scheduling.
- Wallet.
- Payment.
- Meeting engine.
- Homework.
- Public reviews.
- Referrals.
- AI recommendations.
- Packages/subscriptions.

## Tests Audit

Passing coverage present:

- Bookable instructor can create timezone-scoped availability.
- Non-bookable instructor cannot publish active availability.
- Overlapping active windows are rejected.
- Slot generation expands weekly windows in instructor timezone.
- Time off stores local input as UTC.
- Time off blocks generated slots.
- Instructor frontend availability page can be used by an instructor.
- Existing booking, marketplace, student, learning-plan, and instructor-onboarding regressions pass through full suite.

Missing or weak coverage:

- Invalid time range focused tests.
- Frontend toggle/delete weekly window tests.
- Frontend create/delete time-off tests.
- Cross-user instructor denial tests for availability and time off.
- Admin permission tests for create/edit/delete/activate/deactivate.
- Tests proving Filament actions are service-backed.
- Tests proving generated slots do not create booking/payment/wallet/homework records.
- DST/edge-day slot coverage.

## Documentation Audit

Confirmed:

- `docs/architecture/phase-6-instructor-availability-foundation.md` exists and documents the foundation.
- `docs/booking.md` was updated with timezone-aware availability behavior.

Needs update after Phase 6.3:

- Correct admin docs once table row and bulk deletes are service-backed.
- Add explicit policy/ownership boundary after services are hardened.
- Add timezone fallback UX note.

## Duplicate Prevention Check

| Term / Concept | Result |
|---|---|
| `teacher_availability` | Valid existing weekly availability table. |
| `teacher_unavailability` | Valid existing time off / blackout table. |
| `instructor_availability` | Used in service/controller/docs naming; no table duplicate. |
| `instructor_availability_rules` | No table/model created. |
| `instructor_time_off` | Service/docs naming only; no table duplicate. |
| `availability_exceptions` | No table/model created. |
| `slots` / `generated_slots` | Dynamic slot generation only; no generated slot table. |
| `booking_slots` | No table/model created. |
| `reservations` | No Phase 6 reservation table created. |
| `bookings` | Valid existing booking module; not expanded. |
| `payments` | Existing placeholder/payment views/settings only; not expanded. |
| `wallets` | No Phase 6 wallet table created. |
| `meetings` | Not expanded. |
| `homework` | Valid existing module; not expanded. |
| `reviews` | Existing/future surfaces only; not expanded. |
| `instructors` | Valid route/domain naming; no instructor table duplicate. |
| `instructor_profiles` | No table/model created. |
| `users` | Valid base identity table. |
| `user_profiles` | Valid shared profile/lifecycle table. |

## Commands

| Command | Result |
|---|---|
| `php artisan migrate` | Passed: nothing to migrate. |
| `php artisan test` | Passed: 1825 tests, 4014 assertions. |
| `php artisan migrate:status` | Passed; Phase 6 migration applied in batch 36. |
| `php artisan route:list` | Passed; 218 routes. |
| `./vendor/bin/pint --test` | Passed. |
| `composer validate` | Passed. |
| `npm run build` | Not run; no frontend/admin asset files changed during this audit. |

## Final Decision

Phase 6.1 is structurally sound and has no blocking issues, but it is **not yet SAFE TO PROCEED TO PHASE 7**.

Required hardening before Phase 7:

1. Replace Filament table row delete and bulk delete for Teacher Availability with service-backed actions.
2. Replace Filament table row delete and bulk delete for Teacher Leave with service-backed actions.
3. Add service-level actor ownership/admin permission guards or a documented authorization wrapper.
4. Add focused tests for cross-user denial, admin permissions, service-backed Filament actions, invalid ranges, frontend delete/toggle/time-off operations, and no out-of-scope record creation.
5. Add instructor timezone missing-state UX.

Recommended next phase: **Phase 6.3 - Availability Admin, Policy & Test Hardening**.
