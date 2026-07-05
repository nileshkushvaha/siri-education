# Phase 2.3 Instructor Onboarding UI Hardening

## Summary

Phase 2.3 adds a minimal self-service frontend wizard for instructor applicants. It does not create new instructor, profile, application, education, experience, subject, or document tables.

The wizard reuses:

- `users` for identity
- `user_profiles` for profile, lifecycle, onboarding metadata, and Media Library KYC collections
- `UserProfile.instructor_status` for lifecycle
- `InstructorOnboardingService` for onboarding business rules
- `Subject` master data for new subject selection
- `TeacherSubject` as the compatibility assignment table
- `UserEducation` and `UserExperience`
- `AcademicLevel`, `SkillLevel`, and `Language`
- existing account/dashboard layout components

## Routes And Components

Route:

- `GET /dashboard/instructor/onboarding` named `dashboard.instructor.onboarding`

Existing routes retained:

- `POST /dashboard/instructor/start`
- `POST /dashboard/instructor/submit`

Component:

- `App\Livewire\Frontend\Instructor\OnboardingWizard`
- View: `resources/views/livewire/frontend/instructor/onboarding-wizard.blade.php`
- Page shell: `resources/views/instructor/onboarding.blade.php`

The dashboard cards now link to the wizard as the primary Continue/Start path.

## Wizard Steps

1. Overview: status, progress, missing items, start/continue/review actions.
2. Professional Profile: headline, bio, teaching experience summary, teaching philosophy, profile photo, optional introduction video.
3. Subjects & Teaching Preferences: `Subject` IDs, `AcademicLevel` IDs, optional `SkillLevel` IDs, `Language` IDs, country, timezone.
4. Education: create/update/delete own `UserEducation` records.
5. Experience: create/update/delete own `UserExperience` records.
6. Verification Documents: upload/replace required private KYC documents.
7. Review & Submit: missing-item summary and submit action.

## Service Layer Reuse

`InstructorOnboardingService` remains the business boundary. The Livewire component delegates:

- start application
- profile/preference updates
- subject sync
- education create/update/delete
- experience create/update/delete
- profile/KYC media uploads
- submit application

Submitted, under-review, approved, active, rejected, suspended, vacation, and archived applications are locked from applicant-side edits. Draft and documents-pending applications remain editable.

## Subject Master Usage

New onboarding subject selection uses `subjects.id`. The service writes:

- `teacher_subjects.subject_id` = selected `subjects.id`
- `teacher_subjects.subject` = selected `subjects.name` for backward-compatible booking/string display

No free-text subject input was introduced.

## KYC Handling

Required KYC collections remain on `UserProfile`:

- `government_id`
- `address_proof`
- `education_certificate`
- `teaching_certificate`
- `resume`

Optional:

- `introduction_video`

Uploads use Spatie Media Library and the existing private `local` disk configuration for KYC collections. The UI only shows uploaded/not-uploaded state and does not expose storage paths.

## Authorization And Middleware

The wizard route lives inside the existing dashboard group:

- `auth`
- `email.verify.if.required`
- `EnsureAccountIsActive`
- `password.change.required`
- `session.track`
- `frontend.portal`

The service still enforces verified email before start/submit. Guest access redirects to login.

## Validation

Livewire validates form shape and file type/size. `InstructorOnboardingService` enforces lifecycle rules, editability, subject/master-data filtering, required-item completeness, duplicate submission blocking, and audit logging.

## Admin Review Hardening

The generic Filament User form no longer exposes a direct editable `profile.instructor_status` select. Status is displayed read-only; reason-required review transitions remain on service-backed header actions.

## Tests

`tests/Feature/Instructor/InstructorOnboardingWizardTest.php` covers:

- guest denied
- verified user access
- start once/no duplicate profile
- unverified submit blocked
- profile save
- subject/level/language selection using master data
- education add/update
- experience add/update
- private KYC uploads
- complete submit and duplicate submit block
- submitted applicant not public/bookable
- generic admin status select removed

## Remaining Gaps

- No advanced instructor dashboard widgets.
- No availability, booking, earnings, payments, meetings, reviews, homework, or analytics work.
- The wizard is intentionally compact; richer UX can be added without changing the data model.
