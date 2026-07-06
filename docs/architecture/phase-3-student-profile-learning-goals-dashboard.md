# Phase 3 Student Profile, Learning Goals And Dashboard

## Decision

Phase 3 reuses `users` as the identity table and `user_profiles` as the shared student/instructor profile table. No `students` or `student_profiles` table was created.

The prerequisite gate was verified in `docs/audits/phase-2-instructor-onboarding-final-audit.md`: readiness score 93/100, decision SAFE TO PROCEED TO PHASE 3, blocking issues none.

## Student Profile

Existing profile fields remain the foundation: name fields on `users`, phone/country/timezone/language/student status on `user_profiles`, and profile photo through the existing `avatar` Media Library collection.

Phase 3 adds only missing student preference fields:

- `user_profiles.student_academic_level_id`
- `user_profiles.student_preferred_language_id`
- `student_preferred_subjects` pivot using `subjects.id`

Preferred subjects are normalized for FK integrity, duplicate prevention, reporting, and future recommendations. No JSON preferred-subject field was added.

## Learning Goals

`student_learning_goals` stores owned student goals with `subject_id`, optional `academic_level_id`, title, type, description, optional target date, priority, status, audit actor columns, completion/archive timestamps, and soft deletes.

Goal types:

- academic
- exam_preparation
- professional
- personal
- skill_development
- other

Academic level is required only for academic and exam-preparation goals. Other goal types may omit it.

## Favorite Instructors

The existing Wishlist page was a placeholder and had no table/model. Phase 3 reuses that navigation surface as Favorite Instructors while keeping the route name `dashboard.wishlist` for compatibility.

`student_favorite_instructors` stores student/instructor user IDs with a unique constraint. Adding a favorite requires the instructor to be active, public, and in a bookable instructor status. If an instructor later becomes non-bookable, the favorite row remains but dashboard bookable counts exclude it.

## Dashboard

`StudentDashboardService` aggregates profile completion, preferred subjects, active goals, favorite instructors, existing booking/homework summaries, and safe empty states for future wallet/payment/meeting modules.

No wallet, payment, meeting, booking engine, homework engine, review engine, referral engine, or learning-plan engine was expanded in this phase.

## Admin

No `StudentResource` was created. User identity remains managed through the existing User Resource. The Student tab now shows a read-only student overview.

`StudentLearningGoalResource` manages only learning goal records and is policy-backed with Shield-style permissions.

## Authorization And Audit

Student services keep write logic outside controllers and Livewire components:

- `StudentProfilePreferenceService`
- `StudentLearningGoalService`
- `StudentFavoriteInstructorService`
- `StudentDashboardService`

Important mutations are logged through `AuditTrailService`, with safe metadata only.

Policies protect learning goals and favorite instructors so students manage only their own records unless an admin has explicit permissions.

## Remaining Gaps

- Favorite buttons were added to public instructor profiles only; marketplace-card favorite controls can be added later without changing storage.
- Learning goals are not a full learning-plan engine.
- Billing country/currency remains display-only through profile country defaults.
