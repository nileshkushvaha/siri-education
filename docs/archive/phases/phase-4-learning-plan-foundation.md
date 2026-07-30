# Phase 4 Learning Plan Foundation

## Decision

Phase 4 introduces Learning Plans as living academic contracts between students and instructors. A plan is not auto-created at registration and is not a static schedule. Plans begin from an existing `StudentLearningGoal`, then evolve through instructor assignment, assessment, milestones, reviews, adjustments, completion, and archival.

The phase reuses:

- `users` for students and instructors
- `user_profiles` for lifecycle/profile state
- `student_learning_goals` as the goal source
- `subjects` as academic master data
- `academic_levels` as contextual academic level data
- existing dashboard, Livewire, Filament, policy, permission, and `AuditTrailService` conventions

No duplicate student, instructor, profile, subject, booking, wallet, payment, homework, or review engine structures were created.

## Learning Plan Model

`student_learning_plans` stores the current academic contract:

- student owner
- source learning goal
- nullable primary instructor
- subject and optional academic level
- plan summary/current focus/current level notes
- recommended lesson frequency and duration
- preferred schedule note
- progress percentage
- lifecycle timestamps
- actor audit columns
- soft deletes

Only one open plan is allowed per learning goal. Completed or archived plans remain historical. Phase 4.2 moved the duplicate-open-plan check inside a transaction with a row lock on the source learning goal, keeping the guard portable for MySQL without adding a database-specific partial index.

## Lifecycle

`LearningPlanStatus` values:

- draft
- awaiting_assessment
- active
- paused
- review_due
- completed
- archived

Completed and archived plans are read-only for normal academic updates.

## Assessments

`learning_plan_assessments` stores initial, progress, and final assessments. Assigned instructors or permitted admins can create assessments. Students can view their own assessments.

Assessment records never create bookings, payments, meetings, or homework.

## Milestones

`learning_plan_milestones` stores manually created plan milestones. Assigned instructors or permitted admins can create and complete milestones. Completing milestones recalculates the plan progress percentage.

This is not a curriculum or competency engine.

## Progress Reviews

`learning_plan_reviews` stores periodic instructor reviews. Reviews are created by assigned instructors or permitted admins and can clear `review_due` back to active.

Reviews do not create homework, booking, payment, or review-engine records.

## Adjustment History

Phase 4 uses a dedicated `learning_plan_adjustments` table. Major plan updates require a reason and store previous/new values for the changed plan fields. Sensitive long-form educational content is not duplicated into activity properties.

Activity Log still records high-level events through `AuditTrailService`.

## Services

`Student\Services\LearningPlanService` owns the business lifecycle:

- create draft from learning goal
- assign instructor
- record assessment
- activate plan
- adjust plan with history
- create/complete milestones
- create progress review
- mark review due
- complete plan
- archive plan

Controllers and Livewire components stay thin and delegate to the service.

## Permissions

Policies enforce:

- students can view their own plans and related records
- students can create draft plans only from their own goals
- assigned instructors can manage assigned plans
- unrelated instructors cannot access unrelated plans
- admins require explicit Shield-style permissions
- public users cannot access plans

## Dashboard Integration

The student dashboard reads learning plan summary data only:

- active plan count
- current active plan
- assigned instructor
- progress percent
- milestone count

No out-of-scope records are created.

## Admin

`StudentLearningPlanResource` manages plan records only. It does not duplicate student or instructor identity. Student and instructor identity remain on `UserResource`/existing user structures.

Phase 4.2 hardened the resource:

- The generic create route was removed.
- Generic edit fields that affect lifecycle, assignment, subject, academic level, progress, and major academic notes are read-only on edit.
- Admins must use explicit service-backed actions for assignment, activation, review-due marking, adjustment, completion, and archival.
- Adjustments require a reason and write `learning_plan_adjustments` through `LearningPlanService`.
- Lifecycle actions write activity logs through `AuditTrailService`.
- Actions are gated by the `StudentLearningPlanPolicy` update permission.

Child assessment, milestone, and review records are still managed through the service-backed instructor/admin workflows rather than standalone duplicate resources.

## Instructor Dashboard Counters

Phase 4.2 added read-only instructor dashboard counters:

- assigned active learning plans
- review due plans
- assigned plans awaiting assessment

These counters query learning plan state only. They do not create bookings, payments, homework, meetings, or reviews.

## Intentionally Excluded

Phase 4 intentionally does not build:

- availability engine
- booking changes or recurring lesson scheduling
- wallet/payment
- meeting engine
- homework engine expansion
- review engine
- curriculum/roadmap/competency engine
- AI recommendations
- parent accounts
- packages/subscriptions

## Future Integrations

Later phases may connect plans to curriculum maps, bookings, homework, reviews, analytics, and recommendations. Those integrations should read this foundation rather than replacing it.
