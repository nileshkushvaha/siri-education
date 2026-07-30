# Phase 4.2 Learning Plan Admin & UI Hardening

## Decision

Phase 4.2 hardens the existing Learning Plan Foundation after the strict Phase 4.1 audit. It does not introduce a new learning plan concept, duplicate tables, or out-of-scope marketplace/booking/payment behavior.

The hardening focus is:

- prevent generic admin CRUD from bypassing learning plan lifecycle services
- prove admin permissions around learning plan actions
- add practical Livewire coverage for student/instructor pages
- add read-only instructor dashboard counters
- tighten duplicate open-plan prevention with a portable application-level lock

## Filament Admin Hardening

`StudentLearningPlanResource` remains the admin surface for learning plan records only.

The generic create page is removed. New learning plans should originate from a student learning goal through `LearningPlanService::createDraftFromGoal()`.

The generic edit form is now read-only for lifecycle-sensitive or academic-contract fields:

- student
- source learning goal
- primary instructor
- status
- subject
- academic level
- title
- summary
- recommended frequency
- recommended lesson duration
- progress percent
- current focus
- target completion date

Admins must use explicit service-backed actions:

- Assign Instructor
- Activate
- Mark Review Due
- Adjust Plan
- Complete
- Archive

These actions call `LearningPlanService`, so eligibility checks, lifecycle rules, adjustment history, and activity logging remain centralized.

## Adjustment Reason Requirement

Admin plan adjustment uses `LearningPlanService::adjustPlan()`.

The action requires a reason. The service records:

- changed actor
- change reason
- previous values
- new values
- activity log event

This prevents silent overwrite of major academic contract fields.

## Permission Hardening

Actions are visible only when the current admin can update the plan through `StudentLearningPlanPolicy`.

Focused tests cover:

- admin without learning plan permission cannot access the resource
- permitted admin can view the resource
- generic create route is removed
- non-permitted admin cannot manage lifecycle actions
- permitted admin performs lifecycle actions through service-backed Filament actions
- adjustment reason is required

## Livewire Coverage

Practical Livewire tests cover:

- student learning plan page is owner scoped
- student can start a draft from their own learning goal through the component
- instructor workbench shows only assigned plans
- unrelated instructors do not see another instructor's assigned plans
- assigned instructor can record assessment, create milestone, and create progress review through the component

This remains feature/Livewire coverage, not full browser automation.

## Instructor Dashboard Counters

The instructor dashboard now shows read-only learning plan counters:

- assigned active learning plans
- review due plans
- assigned plans awaiting assessment

The counters query `student_learning_plans` only and do not create or mutate any learning plan, booking, payment, homework, meeting, or review data.

## Duplicate Open Plan Guard

`LearningPlanService::createDraftFromGoal()` now locks the source learning goal row inside the create transaction before checking for another open plan.

This keeps the guard portable for MySQL and avoids a database-specific partial unique index. The service-level duplicate prevention test remains in place.

## Out Of Scope

Phase 4.2 does not expand:

- availability
- booking
- recurring lessons
- wallet
- payment
- meeting
- homework
- public reviews
- referrals
- AI recommendations
- packages
- subscriptions

No duplicate student, instructor, profile, learning plan, booking, payment, wallet, homework, or review tables were created.
