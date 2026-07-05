# Phase 2.2 Strict Instructor Onboarding Audit

## Executive Decision

Readiness score: **84/100**

Decision: **PROCEED WITH CAUTION**

Phase 2.1 successfully establishes the instructor onboarding and verification foundation without duplicating identity, profile, education, experience, subject, or application concepts. The implementation reuses `users`, `user_profiles`, `user_educations`, `user_experiences`, `teacher_subjects`, `subjects`, `languages`, Spatie Permission, Filament Shield-style permissions, Spatie Media Library, and `AuditTrailService`.

The system is not marked "SAFE TO PROCEED TO PHASE 3" because the self-service onboarding UI remains lightweight: users can start, see progress, and submit, but there is no complete frontend wizard for editing instructor-only fields, selecting subjects/languages/levels, uploading KYC documents, or managing education/experience from the onboarding flow itself. The service layer supports those behaviors and is tested; the end-user UI is not yet complete enough to call the full onboarding experience finished.

## Blocking Issues

None after audit fix.

During this audit, one audit-blocking issue was found and fixed: the Filament **Force Approve** action was visible based on instructor role/status only. It now reuses the same instructor review permission check as the normal review actions.

## Non-Blocking Issues

1. **Frontend onboarding is foundation-level, not a complete wizard.** The dashboard shows progress and Start/Continue/Submit actions, but editing subjects, academic levels, teaching languages, instructor-specific text fields, education, experience, and KYC documents is not available in one guided onboarding UI.
2. **Admin status select can bypass review reasons.** The User Resource still exposes `profile.instructor_status` in the generic Instructor Controls form. Status changes are logged by the profile observer, but changing to rejected/documents-pending through the select does not require a reason like the service actions do.
3. **No dedicated applicant KYC upload UI.** KYC collections exist on `UserProfile` and are private, but uploads are currently proven through tests and Filament admin fields, not an applicant-facing document upload step.
4. **Review surface is UserResource-based.** This avoids duplication, but the admin experience is not an application-focused queue. Filtering by instructor status exists; a dedicated query/view may be useful later if volume grows.
5. **Notifications are limited to existing profile-status notification wiring.** No new onboarding-specific events/listeners were introduced. This is acceptable for Phase 2.1 but should be revisited when user-facing workflow messages are required.

## Files Created In Phase 2.1

| File | Purpose | Necessary | Similar Existing File | Duplicate Risk |
|---|---|---:|---|---|
| `app/Http/Controllers/Instructor/InstructorOnboardingController.php` | Thin route endpoint for start/submit onboarding. | Yes | Existing public instructor controller handles listing/detail only. | Low |
| `app/Livewire/Frontend/Instructor/DashboardOverview.php` | Instructor dashboard summary and onboarding progress data. | Yes | Student dashboard overview exists for student portal. | Low |
| `app/Services/Instructor/InstructorOnboardingService.php` | Main lifecycle, validation, subject sync, review, and audit logic. | Yes | `InstructorService` is read-side only. | Low |
| `database/migrations/2026_07_10_100000_add_instructor_onboarding_fields_to_user_profiles_table.php` | Adds onboarding fields to `user_profiles`. | Yes | Existing `user_profiles` table. | Low; additive to existing profile table |
| `resources/views/livewire/frontend/instructor/dashboard-overview.blade.php` | Instructor dashboard UI and progress card. | Yes | Student overview view exists. | Low |
| `tests/Feature/Instructor/InstructorOnboardingServiceTest.php` | Feature coverage for lifecycle, validation, subjects, docs, and admin review. | Yes | Existing instructor tests cover listing/detail/lifecycle. | None |
| `docs/architecture/phase-2-instructor-onboarding-verification.md` | Architecture record for Phase 2.1. | Yes | User lifecycle and subject reconciliation docs. | None |
| `database/seeders/InstructorPermissionSeeder.php` | Seeds dedicated instructor application review permission. | Yes after audit hardening | Existing module permission seeders. | None |

## Files Modified

| File | Change | Why | Backward-Compatible | Affects Existing Flows |
|---|---|---|---:|---|
| `app/Models/UserProfile.php` | Added instructor onboarding fillables/casts and KYC media collections. | Reuse profile for application state and documents. | Yes | Profile/media only; booking unaffected |
| `app/Filament/Resources/Users/Pages/EditUser.php` | Added review actions; normal actions call `InstructorOnboardingService`; Force Approve now uses review permission check. | Admin review workflow and audit hardening. | Yes | Admin User edit only |
| `app/Filament/Resources/Users/Schemas/UserForm.php` | Added Instructor tab, status controls, and private document fields. | Admin review/detail surface without duplicate resource. | Yes | Admin User form only |
| `app/Filament/Resources/Users/Tables/UsersTable.php` | Added instructor status column/filter. | Admin can find applications by status. | Yes | Admin listing only |
| `routes/web.php` | Added `POST /dashboard/instructor/start` and `/submit` under dashboard middleware. | Frontend start/submit entry points. | Yes | Dashboard only |
| `resources/views/dashboard/index.blade.php` | Added onboarding progress card and actions for student/default dashboard. | Become-instructor entry point. | Yes | Dashboard only |
| `resources/views/livewire/frontend/instructor/dashboard-overview.blade.php` | Added Start/Continue/Submit buttons. | Instructor-only users need same actions. | Yes | Dashboard only |
| `database/seeders/DatabaseSeeder.php` | Calls `InstructorPermissionSeeder`. | Make review permission available in standard seed flow. | Yes | Seed data only |
| `tests/Feature/Instructor/InstructorOnboardingServiceTest.php` | Added email verification, duplicate submission, dedicated review permission, and reason tests. | Phase 2.2 hardening. | Yes | Tests only |
| `docs/architecture/phase-2-instructor-onboarding-verification.md` | Documents verified email, duplicate submission, and review permission. | Keep architecture doc accurate. | Yes | Docs only |

## Migrations

### `2026_07_10_100000_add_instructor_onboarding_fields_to_user_profiles_table.php`

- Table: `user_profiles`
- Adds: `instructor_teaching_experience_summary`, `instructor_teaching_philosophy`, `instructor_academic_level_ids`, `instructor_skill_level_ids`, `instructor_teaching_language_ids`, `instructor_application_started_at`, `instructor_application_submitted_at`, `instructor_reviewed_at`, `instructor_reviewed_by`, `instructor_review_reason`, `instructor_documents_requested_reason`
- Existing table reused: yes
- Safe: yes, nullable/additive fields only
- Rollback: drops added columns and FK
- Production data risk: low; no destructive data rewrite
- Duplicate structures: no `instructors`, `instructor_profiles`, or `instructor_applications` table

### Related Existing Migration Checks

- `2026_07_08_100200_enforce_unique_user_id_on_user_profiles_table.php` preserves one profile per user.
- `2026_07_08_100000_expand_instructor_status_lifecycle.php` keeps lifecycle on `user_profiles.instructor_status`.
- `2026_07_09_100000_add_subject_id_to_teacher_subjects_table.php` keeps `TeacherSubject` and adds nullable `subject_id`.

Confirmed:

- No duplicate `instructors` table.
- No duplicate `instructor_profiles` table.
- No duplicate `instructor_applications` table.
- `user_profiles.user_id` uniqueness is preserved.
- `UserProfile.instructor_status` remains the lifecycle source.

## Models And Relationships

- `User`: remains base identity; owns `profile`, `educations`, `experiences`, `teacherSubjects`.
- `UserProfile`: remains lifecycle/profile holder; stores instructor status, onboarding metadata, and private KYC media collections.
- `UserEducation`: reused for education records; no instructor-specific duplicate table.
- `UserExperience`: reused for experience records; no instructor-specific duplicate table.
- `Subject`: long-term academic master data; has `teacherSubjects()`.
- `TeacherSubject`: kept for booking compatibility; new onboarding writes `subject_id` and a copied `subject` name.
- `Language`: reused for teaching language IDs in service.
- `AcademicLevel`: reused for academic level IDs in service.
- `SkillLevel`: storage field exists, but service only filters IDs as a plain array today; stronger master validation should be added later.
- New instructor onboarding models: none.

## Instructor Onboarding Flow

Verified by code and tests:

- Email-verified user can start onboarding.
- Non-email-verified user cannot start or submit.
- Starting twice does not duplicate profile/application.
- Draft can be resumed.
- Required fields are checked by `missingRequiredItems()`.
- Incomplete submission is blocked.
- Complete submission moves to `submitted`.
- Submission is audit logged.
- Duplicate submission is blocked after submission/review/bookable states.

Not fully implemented in UI:

- A guided self-service form for all onboarding fields.
- Applicant-facing KYC document upload.
- Applicant-facing subject/language/academic-level pickers.

## Instructor Profile Data

| Field | Status |
|---|---|
| Professional headline | Implemented; reuses `user_profiles.headline`; profile UI exists |
| Biography/about | Implemented; reuses `user_profiles.bio`; profile UI exists |
| Teaching philosophy | Implemented in schema/service; no dedicated applicant UI yet |
| Experience summary | Implemented in schema/service; no dedicated applicant UI yet |
| Subjects via `subjects.id` | Implemented in service/tests; no dedicated applicant UI yet |
| Academic levels | Implemented in schema/service/tests; no dedicated applicant UI yet |
| Skill levels | Storage implemented; validation weaker than subject/academic/language |
| Teaching languages | Implemented in schema/service/tests; no dedicated applicant UI yet |
| Profile photo | Implemented via existing `avatar` media collection and profile UI |
| Introduction video | Media collection and admin upload implemented; no applicant UI yet |
| Country/timezone | Reused from `user_profiles` and existing profile UI |

## Subject Selection Audit

Confirmed:

- New onboarding uses `Subject::availableForAssignment()` and writes `subject_id`.
- No new free-text subject input was introduced in the onboarding service.
- `TeacherSubject.subject` remains a backward-compatible fallback.
- Existing subject reconciliation tests pass.
- Existing booking subject matching is still string-based and unchanged.
- No duplicate `InstructorSubject` table/resource exists.

Risk:

- Booking filters still use `TeacherSubject.subject` strings by design. This is documented Phase 2.0 compatibility, not a Phase 2.1 regression.

## KYC And Document Handling

Confirmed:

- KYC documents use Spatie Media Library collections on `UserProfile`.
- Required collections: `government_id`, `address_proof`, `education_certificate`, `teaching_certificate`, `resume`.
- Optional collection: `introduction_video`.
- KYC collections use the `local` disk.
- Sensitive file paths are not manually stored in domain tables.
- KYC documents are not shown in public instructor cards/listings.
- Admin review uses Filament `SpatieMediaLibraryFileUpload` inside the User Resource form.

Risks:

- Applicant-facing KYC upload is not implemented.
- Direct file authorization for generated Media Library admin URLs depends on Filament/admin access; no public route intentionally exposes KYC documents.

## Admin Review Flow

Implemented:

- List users and filter by instructor status.
- View applicant profile summary in User Resource.
- View/edit verification documents in User Resource.
- Move to `under_review`.
- Request documents with reason.
- Approve with optional note.
- Reject with required reason.
- Force approve with required override reason.
- Review actions are permission-controlled.
- Critical actions are audit logged through `InstructorOnboardingService` or `AuditTrailService::logOverride()`.

Gaps/Risks:

- No dedicated application queue resource; intentionally reuses `UserResource`.
- Generic `instructor_status` select can still bypass service-level reason requirements.
- Education/experience are not surfaced in the shown Instructor tab excerpt as a complete review section; they remain existing related profile data.

## Instructor Status Lifecycle

All expected statuses exist in `InstructorStatus`:

- `draft`
- `submitted`
- `under_review`
- `documents_pending`
- `interview_required`
- `approved`
- `active`
- `vacation`
- `suspended`
- `archived`
- `rejected`

Bookable statuses remain exactly:

- `approved`
- `active`

Not bookable:

- `draft`
- `submitted`
- `under_review`
- `documents_pending`
- `interview_required`
- `vacation`
- `suspended`
- `archived`
- `rejected`

## Public And Booking Safety

Confirmed:

- `InstructorService::baseQuery()` only lists active users with public profiles and `InstructorStatus::bookableValues()`.
- `InstructorService::publicProfile()` forbids non-bookable instructors for non-owner/non-manager viewers.
- `InstructorPolicy` only allows public view for active/bookable instructors.
- `TeacherCandidateRepository` only returns instructors whose profiles are in `InstructorStatus::bookable()`.
- Submitted applicants are not public/bookable.

## Filament Admin Audit

Confirmed:

- No duplicate Instructor Application resource exists.
- User Resource is reused.
- Normal review actions delegate to `InstructorOnboardingService`.
- Status filter exists.
- Sensitive KYC documents are not table columns.
- No unsafe instructor bulk review action exists.

Risk:

- Force Approve remains an inline action rather than a dedicated service action. It is small, reason-required, permission-gated after this audit, and logged as an override, but it should move into a service if override logic grows.

## Frontend / Livewire Audit

Confirmed:

- Dashboard routes use `auth`, conditional email verification, active account, password-change, session tracking, and frontend portal middleware.
- Controllers are thin and call `InstructorOnboardingService`.
- Livewire dashboard component is read-side only and calls the service for progress.
- Server-side service validation is enforced.
- Users can resume draft onboarding.

Gaps:

- No complete onboarding wizard.
- No applicant-facing KYC upload.
- No applicant-facing subject/language/level selection.
- No onboarding-specific education/experience management route.

## Services / Actions Audit

Created/modified service:

- `InstructorOnboardingService`

Responsibilities:

- Start application.
- Update profile data.
- Sync Subject-master-backed teacher subjects.
- Validate missing required items.
- Submit application.
- Admin review transitions.
- Progress calculation.

Assessment:

- Single service is acceptable for Phase 2.1 foundation.
- Transactions are used for write workflows.
- Activity logging exists for start, update, submit, under-review, documents requested, approved, and rejected.
- Tests exist for core service behavior.

Expected separate action classes were not created. This is not blocking because the project already has service-based conventions and the service is cohesive enough today.

## Events And Notifications

No new onboarding-specific events were created.

Existing related notification path:

- `NotifyInstructorOnProfileActivity`
- `InstructorProfileStatusNotification`

Assessment:

- No duplicated events/listeners.
- No circular dependencies found.
- Notification coverage is limited to profile status activity. Missing onboarding-specific notifications are future/non-blocking.

## Activity And Audit Logging

Confirmed:

- Application started is logged.
- Application updated is logged.
- Application submitted is logged.
- Under-review transition is logged.
- Document request is logged with reason.
- Approval is logged with actor and reason when provided.
- Rejection is logged with actor and reason.
- Force approve is logged as `admin_override` with reason.
- Sensitive KYC file paths are not written into audit log properties by onboarding service.
- `AuditTrailService` is used.

## Permissions And Policies

Confirmed:

- Frontend routes require authenticated active frontend portal users.
- Start/submit requires verified email at service level.
- Review actions require `instructor.applications.review` or existing `Update:User` compatibility.
- Public instructor access remains policy/service gated by bookable status.
- KYC documents are private media on `local` disk and not exposed in public views.

Risks:

- Applicant self-edit of education/experience relies on existing/future profile surfaces; policies allow owner update where used, but the self-service UI is incomplete.
- Generic admin status select can bypass reason-required review actions.

## Tests

Passing tests run during this audit:

- Focused: `php artisan test --env=testing tests/Feature/Instructor/InstructorForceApproveOverrideTest.php tests/Feature/Instructor/InstructorOnboardingServiceTest.php tests/Feature/Academic/SubjectTeacherSubjectReconciliationTest.php` — 31 passed, 84 assertions.
- Full suite: `php artisan test` — 1762 passed, 3701 assertions.

Coverage present:

- Start onboarding.
- No duplicate profile/application.
- Email verification block.
- Incomplete submission blocked.
- Complete submission allowed.
- Duplicate submission blocked.
- Subject master data used.
- Legacy subject fallback unaffected.
- KYC private media collections.
- Admin permission checks.
- Rejection requires reason.
- Document request requires reason.
- Lifecycle activity logs.
- Bookability by status.
- Subject reconciliation tests.
- Existing booking tests through full suite.

Weak/missing tests before next phase:

- Browser/Livewire feature tests for actual dashboard start/submit forms.
- Applicant-facing KYC upload tests, once UI exists.
- Applicant-facing subject/language/academic-level picker tests, once UI exists.
- Test proving non-review admins cannot see Force Approve after this audit fix.
- Test proving public users cannot resolve KYC media URLs, if custom media routes are later added.

## Documentation Audit

`docs/architecture/phase-2-instructor-onboarding-verification.md` exists and documents:

- Reused tables/models.
- No-duplicate decision.
- Lifecycle flow.
- KYC handling.
- Subject master usage.
- Admin review flow.
- Permissions.
- Audit logging.
- Remaining gaps.

It is accurate after the Phase 2.2 updates.

## Commands

| Command | Result |
|---|---|
| `php artisan test` | Passed: 1762 tests, 3701 assertions |
| `php artisan migrate:status` | Passed; all listed migrations ran through `2026_07_10_100000_add_instructor_onboarding_fields_to_user_profiles_table` |
| `php artisan route:list` | Passed; 205 routes |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Not run; no frontend/admin asset files changed |

## Duplicate Prevention Check

| Term | Result |
|---|---|
| `instructors` | Valid public route/resource naming; no table/model duplicate |
| `instructor_profiles` | Not found as table/model |
| `instructor_applications` | Not found as table/model |
| `instructor_subjects` | Not found as table/model |
| `instructor_documents` | Not found as table/model |
| `kyc_documents` | Not found as table/model |
| `teacher_subjects` | Valid existing compatibility table |
| `subjects` | Valid academic master table |
| `user_profiles` | Valid shared profile/lifecycle table |
| `user_educations` | Valid reused education table |
| `user_experiences` | Valid reused experience table |

## Recommended Fixes Before Phase 3

1. Build a minimal self-service onboarding wizard on top of `InstructorOnboardingService`.
2. Add applicant-facing KYC document upload using the existing private Media Library collections.
3. Add applicant-facing Subject/AcademicLevel/SkillLevel/Language selectors backed by master data.
4. Either remove the generic admin `instructor_status` select or make status-changing paths route through service actions where reasons are required.
5. Add a focused application review view/query inside UserResource or a non-duplicative Filament page if admin volume warrants it.
6. Add feature tests for the dashboard Start/Continue/Submit route behavior and Force Approve visibility for non-review admins.

## Recommended Next Phase

Run a short **Phase 2.3 Onboarding UI Hardening** pass before Phase 3. Phase 3 should not start with availability, booking expansion, or payments until applicants can complete all required onboarding fields and documents through an actual frontend flow.
