# Phase 2.4 Instructor Onboarding Final Audit

## Executive Decision

Readiness score: **93/100**

Decision: **SAFE TO PROCEED TO PHASE 3**

Phase 2.3 completes the applicant-facing onboarding gap identified in Phase 2.2. Applicants can now complete the required instructor onboarding workflow from the frontend: start/resume, professional profile, master-data-backed teaching preferences, education, experience, private KYC uploads, review, and submit. Admin review remains on the existing User Resource and the generic `profile.instructor_status` select no longer provides a reason-bypassing transition path.

## Blocking Issues

None.

## Non-Blocking Issues

1. **Force Approve non-review visibility is indirectly covered, not directly tested.** The action uses `canReviewInstructor()`, and existing tests cover reason requirements, override logging, already-bookable visibility, and non-instructor visibility. A dedicated non-review-admin hidden/action-denied test should still be added.
2. **Submitted-applicant edit locking is service-enforced but not directly tested for every UI method.** `InstructorOnboardingService::ensureEditable()` blocks writes after submission/review, and the UI disables inputs, but a focused submitted-user edit test would strengthen coverage.
3. **KYC privacy relies on private Media Library storage and no custom public routes.** Current tests confirm local disk/private collection usage. If custom media routes are added later, route-level authorization tests will be required.
4. **Wizard UX is complete but intentionally compact.** It is suitable for Phase 3 readiness, but richer applicant conveniences such as search inside long multi-select lists can be added later without schema changes.

## Files Created

| File | Purpose | Necessary | Similar Existing File | Duplicate Risk | Architecture Fit |
|---|---|---:|---|---|---|
| `app/Livewire/Frontend/Instructor/OnboardingWizard.php` | Frontend self-service onboarding wizard component. | Yes | Instructor dashboard overview existed, but was read-side only. | Low | Thin UI component; delegates business rules to `InstructorOnboardingService`. |
| `resources/views/livewire/frontend/instructor/onboarding-wizard.blade.php` | Wizard UI for profile, preferences, education, experience, KYC, submit. | Yes | Dashboard overview view existed, not a full wizard. | Low | Presentation-only; uses Livewire state and existing account styling. |
| `resources/views/instructor/onboarding.blade.php` | Full-width page shell for onboarding route. | Yes | Existing dashboard pages use account layout. | Low | Reuses `layouts.account`; opts out of sidebar only for this focused workflow. |
| `tests/Feature/Instructor/InstructorOnboardingWizardTest.php` | Feature coverage for applicant-facing wizard behavior. | Yes | Service tests existed but did not prove frontend wizard flow. | None | Tests route access, Livewire actions, KYC, submission, public safety. |
| `docs/architecture/phase-2-instructor-onboarding-ui-hardening.md` | Architecture record for Phase 2.3 UI hardening. | Yes | Phase 2.1 architecture doc existed. | None | Documents reuse decisions and remaining gaps. |
| `docs/audits/phase-2-instructor-onboarding-final-audit.md` | This Phase 2.4 final readiness audit. | Yes | Phase 2.2 audit existed. | None | Audit/reporting only. |

## Files Modified

| File | Change | Why | Backward Compatible | Affects Existing Flows |
|---|---|---|---:|---|
| `app/Http/Controllers/Instructor/InstructorOnboardingController.php` | Added wizard page endpoint and redirected start action to wizard. | Give applicants a real onboarding UI. | Yes | Dashboard/onboarding only. |
| `app/Services/Instructor/InstructorOnboardingService.php` | Added media upload, education, experience, skill-level validation, edit locking, and audit logging support. | Keep wizard business logic in service layer. | Yes | Onboarding only; booking unaffected. |
| `resources/views/dashboard/index.blade.php` | Dashboard onboarding card links to wizard. | Start/continue path now opens full wizard. | Yes | Dashboard only. |
| `resources/views/livewire/frontend/instructor/dashboard-overview.blade.php` | Instructor dashboard actions link to wizard. | Same onboarding path for instructor-role users. | Yes | Dashboard only. |
| `resources/views/layouts/account.blade.php` | Added opt-in `account-full-width` section to hide sidebar. | Let onboarding use full content width without changing other pages. | Yes | Only pages setting the section are affected. |
| `routes/web.php` | Added `GET /dashboard/instructor/onboarding`. | Authenticated wizard route. | Yes | Route is inside existing dashboard middleware group. |
| `app/Filament/Resources/Users/Schemas/UserForm.php` | Replaced editable instructor status select with read-only placeholder. | Prevent reason-required review bypass. | Yes | Admin status changes now stay on review actions. |
| `app/Filament/Resources/Users/Pages/EditUser.php` | Review actions use service permission checks; Force Approve is permission-gated and reason-required. | Harden admin review workflow. | Yes | Admin User edit only. |
| `database/seeders/DatabaseSeeder.php` / `database/seeders/InstructorPermissionSeeder.php` | Seed dedicated instructor review permission. | Standardize review permission availability. | Yes | Seed data only. |
| `tests/Feature/Filament/InstructorTabTest.php` | Updated for read-only status display. | Match hardened admin form. | Yes | Tests only. |
| `tests/Feature/Instructor/InstructorOnboardingServiceTest.php` | Added service hardening coverage. | Validate lifecycle and permission behavior. | Yes | Tests only. |
| `docs/architecture/phase-2-instructor-onboarding-verification.md` | Updated with Phase 2.3/permission hardening notes. | Keep architecture docs current. | Yes | Docs only. |

## Migrations

No new Phase 2.3 or Phase 2.4 migrations were added.

Existing relevant migration:

| Migration | Table | Change | Safe | Duplicate Risk |
|---|---|---|---:|---|
| `2026_07_10_100000_add_instructor_onboarding_fields_to_user_profiles_table.php` | `user_profiles` | Adds nullable onboarding/profile/review fields. | Yes | Low; reuses shared profile table. |

Confirmed:

- No `instructors` table.
- No `instructor_profiles` table.
- No `instructor_applications` table.
- No `instructor_subjects` table.
- No duplicate KYC/document table.
- No `instructor_educations` or `instructor_experiences` table.
- `user_profiles.user_id` uniqueness is preserved by `2026_07_08_100200_enforce_unique_user_id_on_user_profiles_table.php`.
- `UserProfile.instructor_status` remains the lifecycle source.

## Routes And Middleware

Onboarding routes:

- `GET /dashboard/instructor/onboarding` named `dashboard.instructor.onboarding`
- `POST /dashboard/instructor/start` named `dashboard.instructor.start`
- `POST /dashboard/instructor/submit` named `dashboard.instructor.submit`

All live inside the existing dashboard route group:

- `auth`
- `email.verify.if.required`
- `EnsureAccountIsActive`
- `password.change.required`
- `session.track`
- `frontend.portal`

Confirmed:

- Guests redirect to login.
- Authenticated frontend-portal users access only their own onboarding because the component always uses `auth()->user()`.
- Verified email is enforced by `InstructorOnboardingService::start()` and `submit()`.
- Submitted/reviewed users cannot modify restricted fields because service writes call `ensureEditable()`.
- Route names are clear and non-duplicative.

## Wizard Completeness Checklist

| Step | Requirement | Status |
|---|---|---|
| Overview | Status, progress, missing items, next action, Start/Continue/Submit | Complete |
| Professional Profile | Headline, bio, teaching summary, philosophy, profile photo, optional intro video | Complete |
| Teaching Preferences | `Subject`, `AcademicLevel`, `SkillLevel`, `Language`, country/timezone | Complete |
| Teaching Preferences | No free-text subject input | Complete |
| Education | Add/edit/delete own `UserEducation` records | Complete |
| Experience | Add/edit/delete own `UserExperience` records | Complete |
| Verification Documents | Required KYC collections on `UserProfile` via Media Library | Complete |
| Verification Documents | Uploaded/missing state and replacement before submission | Complete |
| Review & Submit | Missing items shown, submit blocked if incomplete, service submit, duplicate blocked | Complete |

## Service Layer Audit

`InstructorOnboardingService` remains the business boundary. The Livewire component validates input shape and delegates lifecycle writes to the service.

Confirmed:

- No duplicated lifecycle business logic in controller/component.
- Transactions are used for start, profile update, media upload, education, experience, submit, and admin transitions.
- Server-side validation exists in Livewire and service.
- Subject sync uses `Subject::availableForAssignment()` and writes `teacher_subjects.subject_id`.
- Education/experience operations use `UserEducation` and `UserExperience`.
- KYC operations use Spatie Media Library on `UserProfile`.
- Submit validation cannot be bypassed by UI because `missingRequiredItems()` is service-side.
- Status transitions are service-controlled, except Force Approve which is explicitly reason-required and logged as an admin override.
- Activity logging uses `AuditTrailService`.

## Subject Reconciliation Review

Confirmed:

- `Subject` remains the future source of truth.
- New onboarding writes `subject_id`.
- `TeacherSubject.subject` is still populated as display/booking fallback.
- Legacy free-text rows continue to work.
- No free-text subject input was introduced in onboarding.
- No `InstructorSubject` table/model/resource exists.
- `SubjectTeacherSubjectReconciliationTest` passes through the full test suite.

## KYC Security Review

Confirmed:

- KYC uploads use existing Media Library collections on `UserProfile`.
- Required collections: `government_id`, `address_proof`, `education_certificate`, `teaching_certificate`, `resume`.
- Optional collection: `introduction_video`.
- Files are stored on the `local` disk in tests and are not public profile assets.
- UI exposes uploaded/missing state, not file paths.
- Audit logs record collection names only, not storage paths.
- Admin review uses the existing User Resource; KYC files are not table columns.

Non-blocking follow-up:

- Add explicit public media-route denial tests if any custom KYC media download route is introduced later.

## Education And Experience Review

Confirmed:

- `UserEducation` is reused.
- `UserExperience` is reused.
- No `instructor_educations` or `instructor_experiences` table exists.
- Applicant UI supports add/edit/delete for own records.
- Ownership is enforced by querying through `auth()->user()->educations()` / `experiences()`.
- Completeness requires at least one active education and one active experience.
- Feature tests cover add/update flows.

## Admin Review Hardening Review

Confirmed:

- Direct editable `profile.instructor_status` select was removed from the generic User form.
- Status is displayed read-only in the Instructor tab.
- Approve requires review permission.
- Reject requires a reason.
- Request Documents requires a reason.
- Force Approve requires review permission and a reason, and is logged as `admin_override`.
- Normal lifecycle transitions call `InstructorOnboardingService`.
- Sensitive KYC documents are not shown in table columns.
- Admin review remains non-duplicative and UserResource-based.

Non-blocking test gap:

- Add a direct test proving a non-review admin cannot see/use Force Approve.

## Bookability And Public Exposure

Confirmed:

- `InstructorStatus::bookable()` remains `[approved, active]`.
- Draft, submitted, under_review, documents_pending, interview_required, vacation, suspended, archived, and rejected are not bookable.
- Submitted applicants are not visible through public profile access.
- `InstructorService::baseQuery()` filters to public profiles with bookable instructor status.
- `TeacherCandidateRepository` remains gated to bookable instructor statuses.
- Existing booking tests pass through the full suite.

## Activity And Audit Logging

Confirmed:

- Application started is logged.
- Application/profile updates are logged.
- Subject/language/level updates are covered by application update logging.
- Education/experience update/delete actions are logged.
- KYC upload/replace is logged without sensitive paths.
- Application submitted is logged.
- Under-review transition is logged.
- Document request is logged with reason.
- Approval is logged with actor and note/reason.
- Rejection is logged with actor and reason.
- Force Approve is logged as an override with reason.
- `AuditTrailService` is used for business audit entries.

## Permissions And Policies

Confirmed:

- Applicant onboarding reads/writes are scoped to `auth()->user()`.
- Applicant cannot approve/reject self through frontend UI.
- Normal user cannot view another applicant's onboarding because there is no user-id route parameter.
- Public users cannot access the onboarding wizard.
- Only permitted admin can run service review actions.
- Force Approve is permission-gated.
- Education/experience ownership checks query through the current user relationship.

Non-blocking gaps:

- Direct tests for non-review admin Force Approve visibility/use should be added.
- Direct tests for attempted edits after submission would strengthen existing service guarantees.

## Tests

| Required Coverage | Status |
|---|---|
| Guest cannot access onboarding wizard | Covered |
| Verified user can access wizard | Covered |
| Non-verified user cannot submit | Covered |
| User can start once / no duplicate profile | Covered |
| Professional profile step saves | Covered |
| Subject picker uses Subject master data | Covered |
| Free-text subject input not used | Covered |
| Academic-level picker works | Covered |
| Skill-level picker implemented | Covered by validation/service; UI present, dedicated test is weak |
| Language picker works | Covered |
| Education add/edit flow | Covered |
| Experience add/edit flow | Covered |
| KYC upload works | Covered |
| KYC privacy | Covered by local/private disk assertions; public route denial is a future-route gap |
| Incomplete application cannot submit | Covered |
| Complete application submits | Covered |
| Duplicate submission blocked | Covered |
| Submitted applicant edit locking | Service-covered; direct UI test missing but non-blocking |
| Generic admin status select cannot bypass review flow | Covered |
| Non-review admin cannot see/use Force Approve | Missing but non-blocking |
| Submitted applicant not public/bookable | Covered |
| Existing booking tests pass | Covered by full suite |
| Existing subject reconciliation tests pass | Covered by full suite |

## Documentation Audit

Existing docs reviewed:

- `docs/architecture/phase-2-instructor-onboarding-verification.md`
- `docs/architecture/phase-2-instructor-onboarding-ui-hardening.md`
- `docs/audits/phase-2-instructor-onboarding-audit.md`

New doc:

- `docs/audits/phase-2-instructor-onboarding-final-audit.md`

Assessment: Accurate after Phase 2.3. The verification doc still correctly describes the Phase 2.1 foundation and notes that Phase 2.3 added the complete wizard.

## Duplicate Prevention Check

| Term | Result | Classification |
|---|---|---|
| `instructors` | Public route/view/service naming only; no table/model. | Adjacent but intentional |
| `instructor_profiles` | Docs only; no table/model. | Valid, no duplicate |
| `instructor_applications` | Docs only; no table/model. | Valid, no duplicate |
| `instructor_subjects` | Docs only; no table/model. | Valid, no duplicate |
| `instructor_documents` | Existing reason field name only; no table/model. | Valid |
| `instructor_educations` | Not found as table/model. | Valid, no duplicate |
| `instructor_experiences` | Not found as table/model. | Valid, no duplicate |
| `kyc_documents` | Test/doc wording only; no table/model. | Valid, no duplicate |
| `teacher_subjects` | Existing compatibility assignment table. | Valid |
| `subjects` | Academic master table. | Valid |
| `user_profiles` | Shared profile/lifecycle table. | Valid |
| `user_educations` | Reused education table. | Valid |
| `user_experiences` | Reused experience table. | Valid |

## Command Results

| Command | Result |
|---|---|
| `php artisan test` | Passed: 1774 tests, 3742 assertions |
| `php artisan migrate:status` | Passed; all migrations ran through `2026_07_10_100000_add_instructor_onboarding_fields_to_user_profiles_table` |
| `php artisan route:list` | Passed; 206 routes |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Not run; no JS/CSS/frontend asset pipeline files changed |

## Final Recommendation

Phase 3 can start.

Recommended next phase: **Phase 3 — Availability, Scheduling, and Booking Expansion**, with one small test-hardening task queued early: add direct tests for non-review admin Force Approve denial and submitted-applicant edit locking. These are non-blocking because service and UI protections are already in place and the full suite passes.
