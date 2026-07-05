# Phase 2.1 Instructor Onboarding & Verification

## Summary

Phase 2.1 adds the instructor application and verification foundation without introducing duplicate identity, profile, instructor, education, or experience tables.

The workflow uses `users` for identity, `user_profiles.instructor_status` for lifecycle, `teacher_subjects.subject_id` for new Subject-master-backed teaching subjects, existing `user_educations` / `user_experiences`, and Spatie Media Library on `UserProfile` for private verification documents.

## Reused Foundations

- `users` remains the base identity table.
- `user_profiles` remains the instructor application/profile state holder.
- `InstructorStatus` remains the lifecycle enum and `InstructorStatus::bookable()` remains unchanged.
- `TeacherSubject` remains the compatibility table for teacher subject coverage; new onboarding writes `subject_id` from `subjects`.
- `Subject`, `AcademicLevel`, `SkillLevel`, and `Language` master data are reused for future-facing selections.
- `user_educations` and `user_experiences` remain the education/experience sources.
- `AuditTrailService` remains the only business audit entry point.
- The existing `UserResource` remains the admin review surface; no duplicate Filament resource was added.

## Data Additions

`user_profiles` gained instructor-specific application fields:

- teaching experience summary
- teaching philosophy
- academic level IDs
- skill level IDs
- teaching language IDs
- application started/submitted/review timestamps
- reviewer and review/request reason fields

No `instructors`, `instructor_profiles`, or `instructor_applications` table was created.

## Documents

KYC documents are stored through Spatie Media Library on `UserProfile`, using the private `local` disk:

- `government_id`
- `address_proof`
- `education_certificate`
- `teaching_certificate`
- `resume`
- `introduction_video` optional

The document collections are not public profile assets.

## Services

`InstructorOnboardingService` owns lifecycle behavior:

- start application
- update professional onboarding data
- validate submission readiness
- submit application
- mark under review
- request documents
- approve
- reject
- calculate dashboard progress

Filament actions and frontend routes call this service instead of embedding business rules in forms.

## Review Rules

Admin review actions require the existing `Update:User` permission. Approval sets `InstructorStatus::Approved` and the verification badge readiness flag. Rejection and document requests require reasons and are audit logged.

Only `Approved` and `Active` remain bookable because `InstructorStatus::bookable()` was not changed.

## Remaining Gaps

This phase intentionally does not build scheduling, availability, bookings, earnings, payments, marketplace listing UI, reviews, homework, or analytics. The frontend onboarding UI is a lightweight dashboard/progress entry point; a fuller self-service wizard can be built on the service without changing the data model.
