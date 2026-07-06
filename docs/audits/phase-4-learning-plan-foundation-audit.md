# Phase 4.1 Strict Learning Plan Foundation Audit

## Executive Decision

Readiness score: **90/100**

Decision: **PROCEED WITH CAUTION**

Phase 4 establishes the Learning Plan Foundation as a living academic contract without duplicating student, instructor, profile, subject, booking, payment, wallet, homework, or review-engine concepts. The implementation reuses `users`, `user_profiles`, `student_learning_goals`, `subjects`, `academic_levels`, approved/active instructor lifecycle checks, Spatie Permission policies, and `AuditTrailService`.

The service layer covers the required foundation: draft plan creation from student learning goals, instructor assignment, assessments, milestones, progress reviews, plan adjustments, progress calculation, completion, archiving, ownership checks, instructor assignment checks, and audit logging.

The system is not marked "SAFE TO PROCEED TO PHASE 5" because two non-blocking hardening items remain: the Filament Learning Plan resource still exposes generic create/edit paths instead of routing major lifecycle/academic changes through service actions, and several lifecycle/permission rules are enforced in the service/tests rather than with database-level constraints or richer browser/UI tests.

## Blocking Issues

None found.

## Non-Blocking Issues

1. **Filament admin edits are generic CRUD.** `StudentLearningPlanResource` manages the correct table and is policy-backed, but generic form edits can change plan fields without using `LearningPlanService::adjustPlan()`. This should be hardened before high-volume admin operations.
2. **Only one open plan per learning goal is service-enforced, not database-enforced.** The service blocks duplicate open plans, and tests cover it. A DB-level partial unique constraint is not portable in the current migration style, so this is acceptable foundation risk.
3. **Child records cascade when a learning plan is hard-deleted.** Plans use soft deletes, and destructive hard delete is not exposed as a normal workflow. If future admin force-delete is introduced, retention policy should be reviewed.
4. **Instructor dashboard summary is minimal.** Instructor can access assigned plan management UI, but a dashboard aggregate for pending assessments/review-due counts is not yet surfaced.
5. **UI tests are service/feature-level, not browser-level.** Routes and ownership are tested; detailed Livewire/browser interaction coverage can be expanded later.

## Files Created In Phase 4

| File | Purpose | Necessary | Similar Existing File | Duplicate Risk | Architecture |
|---|---|---:|---|---|---|
| `app/Enums/LearningPlanStatus.php` | Plan lifecycle enum. | Yes | `LearningGoalStatus` | Low | Matches enum convention |
| `app/Enums/LearningPlanAssessmentType.php` | Assessment type enum. | Yes | None | Low | Scoped to plans |
| `app/Enums/LearningPlanMilestoneStatus.php` | Milestone status enum. | Yes | None | Low | Scoped to plans |
| `app/Models/StudentLearningPlan.php` | Main learning plan contract model. | Yes | `StudentLearningGoal` | Low | Extends goal, does not replace it |
| `app/Models/LearningPlanAssessment.php` | Assessment records for a plan. | Yes | None | Low | Not booking/homework/review engine |
| `app/Models/LearningPlanMilestone.php` | Manual academic milestones. | Yes | None | Low | Not homework/curriculum engine |
| `app/Models/LearningPlanReview.php` | Academic progress reviews. | Yes | None | Medium naming risk only | Not public reviews engine |
| `app/Models/LearningPlanAdjustment.php` | Plan version/change history. | Yes | Activity log | Low | Complements audit trail |
| `app/Services/Student/LearningPlanService.php` | Business workflow service. | Yes | Student goal service | Low | Keeps controllers/Livewire thin |
| `app/Policies/StudentLearningPlanPolicy.php` | Plan access policy. | Yes | Goal policy | Low | Ownership/assignment based |
| `app/Policies/LearningPlanAssessmentPolicy.php` | Assessment access policy. | Yes | None | Low | Student/assigned instructor/admin |
| `app/Policies/LearningPlanMilestonePolicy.php` | Milestone access policy. | Yes | None | Low | Student/assigned instructor/admin |
| `app/Policies/LearningPlanReviewPolicy.php` | Progress review access policy. | Yes | None | Low | Student/assigned instructor/admin |
| `app/Http/Controllers/Student/StudentLearningPlanController.php` | Thin student page controller. | Yes | Student goal controller | Low | Returns view only |
| `app/Http/Controllers/Instructor/InstructorLearningPlanController.php` | Thin instructor page controller. | Yes | Instructor onboarding controller | Low | Returns view only |
| `app/Livewire/Frontend/Student/LearningPlans.php` | Student plan page component. | Yes | Student goal component | Low | Delegates writes to service |
| `app/Livewire/Frontend/Instructor/LearningPlans.php` | Instructor assigned plan workbench. | Yes | Instructor dashboard component | Low | Delegates writes to service |
| `resources/views/student/learning-plans/index.blade.php` | Student page shell. | Yes | Learning goals page | Low | Existing layout |
| `resources/views/instructor/learning-plans/index.blade.php` | Instructor page shell. | Yes | Onboarding page shell | Low | Existing layout |
| `resources/views/livewire/frontend/student/learning-plans.blade.php` | Student plan UI. | Yes | Student goal UI | Low | Minimal frontend |
| `resources/views/livewire/frontend/instructor/learning-plans.blade.php` | Instructor plan UI. | Yes | Instructor dashboard UI | Low | Minimal frontend |
| `app/Filament/Resources/StudentLearningPlans/*` | Admin resource for plan records. | Yes | Student goal resource | Low | No StudentResource duplicate |
| `database/migrations/2026_07_12_100000_create_student_learning_plans_table.php` | Main plan table. | Yes | Goal table | Low | References existing goals/subjects |
| `database/migrations/2026_07_12_100100_create_learning_plan_assessments_table.php` | Assessment table. | Yes | None | Low | Plan-scoped |
| `database/migrations/2026_07_12_100200_create_learning_plan_milestones_table.php` | Milestone table. | Yes | None | Low | Plan-scoped |
| `database/migrations/2026_07_12_100300_create_learning_plan_reviews_table.php` | Academic progress review table. | Yes | None | Medium naming risk | Not public reviews |
| `database/migrations/2026_07_12_100400_create_learning_plan_adjustments_table.php` | Adjustment history table. | Yes | Activity log | Low | Stores old/new changed fields |
| `tests/Feature/Student/LearningPlanFoundationTest.php` | Phase 4 feature/service coverage. | Yes | Student goal tests | Low | Regression-focused |
| `docs/architecture/phase-4-learning-plan-foundation.md` | Architecture record. | Yes | Phase 3 architecture doc | None | Accurate after audit |

## Files Modified

| File | Change | Why | Backward-Compatible | Affected Area |
|---|---|---|---:|---|
| `app/Models/User.php` | Added `studentLearningPlans()` and `assignedLearningPlans()`. | Plan ownership/assignment. | Yes | Student/instructor read model |
| `app/Models/StudentLearningGoal.php` | Added `learningPlans()`. | Create plans from goals. | Yes | Student goals |
| `app/Models/Subject.php` | Added `studentLearningPlans()`. | Subject master relation. | Yes | Subject master |
| `app/Models/AcademicLevel.php` | Added `studentLearningPlans()`. | Optional academic level relation. | Yes | Academic master |
| `app/Providers/AppServiceProvider.php` | Registered plan policies. | Authorization. | Yes | Policies |
| `app/Services/Student/StudentDashboardService.php` | Added read-only learning plan summary. | Student dashboard. | Yes | Dashboard only |
| `app/Livewire/Frontend/Student/DashboardOverview.php` | Exposes plan summary state. | Student dashboard UI. | Yes | Dashboard only |
| `resources/views/livewire/frontend/student/dashboard-overview.blade.php` | Shows current learning plan summary. | Student UX. | Yes | Dashboard only |
| `routes/web.php` | Added student/instructor learning plan routes. | Frontend access. | Yes | Routing |
| `app/Services/Account/AccountMenuService.php` | Added Learning Plans menu entries. | Navigation. | Yes | Portal nav |
| `database/seeders/StudentPermissionSeeder.php` | Added plan-related permissions. | Filament/Shield-style permissions. | Yes | Permissions |

Related Phase 3 files remain present in the working tree and are not considered Phase 4 duplicates.

## Migration Audit

### `student_learning_plans`

- Table: `student_learning_plans`
- Columns: student user, learning goal, optional primary instructor, subject, optional academic level, title, plan notes, recommendations, current focus, target completion date, status, progress percent, lifecycle timestamps, actor columns, timestamps, soft deletes.
- Foreign keys: `student_user_id -> users` cascade, `learning_goal_id -> student_learning_goals` cascade, `primary_instructor_user_id -> users` null, `subject_id -> subjects` restrict, `academic_level_id -> academic_levels` null, actor columns null.
- Indexes: `student_user_id/status`, `primary_instructor_user_id/status`, `learning_goal_id/status`.
- Unique constraints: none; open-plan uniqueness is service-enforced.
- Nullable fields: instructor and plan notes are nullable to support draft stage.
- Rollback: drops table.
- Production risk: low; additive table.
- Duplicate risk: low; does not duplicate users, goals, bookings, payments, homework, or subjects.
- History note: subject deletion is restricted; academic level deletion nulls. Hard-deleting a learning goal cascades, but goals use soft deletes for normal historical preservation.

### `learning_plan_assessments`

- Table: `learning_plan_assessments`
- Columns: plan, student, optional instructor, type, strengths, weaknesses, current level, learning pace, recommended focus/frequency, notes, assessed timestamp, creator, timestamps.
- Foreign keys: plan cascade, student cascade, instructor null, creator null.
- Indexes: `learning_plan_id/assessment_type`, `student_user_id/assessment_type`.
- Rollback: drops table.
- Risk: low; assessment is plan-scoped and does not create booking/payment/homework records.

### `learning_plan_milestones`

- Table: `learning_plan_milestones`
- Columns: plan, title, description, target date, status, completed timestamp, sort order, actor columns, timestamps, soft deletes.
- Foreign keys: plan cascade, actor columns null.
- Indexes: `learning_plan_id/status`.
- Rollback: drops table.
- Risk: low; milestones are not homework assignments.

### `learning_plan_reviews`

- Table: `learning_plan_reviews`
- Columns: plan, student, optional instructor, review number, academic review notes, next focus, reviewed timestamp, creator, timestamps.
- Foreign keys: plan cascade, student cascade, instructor null, creator null.
- Unique: `learning_plan_id/review_number`.
- Indexes: `student_user_id/reviewed_at`.
- Rollback: drops table.
- Risk: medium naming risk only; this is academic progress review, not public marketplace reviews.

### `learning_plan_adjustments`

- Table: `learning_plan_adjustments`
- Columns: plan, changed by, reason, previous JSON values, new JSON values, created timestamp.
- Foreign keys: plan cascade, changed_by null.
- Indexes: `learning_plan_id/created_at`.
- Rollback: drops table.
- Risk: low; stores changed plan fields and does not duplicate sensitive notes into activity log properties.

Confirmed no new `students`, `student_profiles`, `instructors`, `instructor_profiles`, booking, payment, wallet, homework, review-engine, subject, or teacher-subject duplicate table was created.

## Model And Lifecycle Audit

`StudentLearningPlan` belongs to a student user, learning goal, subject, optional academic level, and optional primary instructor. It has assessments, milestones, reviews, adjustments, actor relations, status enum casting, integer progress casting, date casts, active/historical scopes, and soft deletes.

Expected fields are present: `student_user_id`, `learning_goal_id`, nullable `primary_instructor_user_id`, `subject_id`, nullable `academic_level_id`, `title`, `summary`, `initial_assessment`, `recommended_frequency`, `recommended_lesson_duration_minutes`, `preferred_schedule_note`, `current_focus`, `current_level_note`, `target_completion_date`, `status`, `progress_percent`, `started_at`, `completed_at`, `archived_at`, `created_by`, `updated_by`, timestamps, and soft deletes.

Statuses exist: `draft`, `awaiting_assessment`, `active`, `paused`, `review_due`, `completed`, and `archived`.

Lifecycle behavior:

- Draft plans can be edited through service.
- Awaiting assessment supports instructor assessment before activation.
- Active plans can receive milestones and reviews.
- Review due plans can be cleared to active by creating a review.
- Paused is writable and visible but inactive in product semantics.
- Completed/archived plans reject new service writes.
- Created, instructor assigned, assessment recorded, activated, review due, milestone created, milestone completed, progress review created, adjusted, completed, and archived events are activity logged.

## Learning Goal Integration

Verified:

- A student can create a draft plan from their own open learning goal.
- A student cannot create a plan from another student's goal.
- Plan copies subject and academic level from the learning goal.
- Plan creation does not mutate the learning goal.
- One open plan per goal is service-enforced.
- Completed/archived plans remain historical, allowing future historical plan patterns.
- Phase 3 learning goal tests still pass.

## Instructor Assignment Audit

Verified:

- Primary instructor is nullable during draft.
- Only active users with the instructor role and bookable instructor statuses (`approved`, `active`) can be assigned.
- Suspended/non-bookable instructors are rejected.
- Unrelated instructors cannot view/manage plans.
- Assigned instructors can view assigned plans and perform service-permitted academic actions.
- Assignment is activity logged.
- Assignment does not create booking or payment records.

## Assessment, Milestone, Review, And Adjustment Audit

Assessments:

- Belong to a learning plan and student.
- Can reference an instructor.
- Assigned instructor or permitted admin can create through service.
- Student can view own assessment; other students cannot.
- Does not create booking, homework, or payment records.
- Updates plan fields only through service.
- Activity logging exists.

Milestones:

- Belong to a learning plan.
- Assigned instructor/admin can create through service.
- Student can view milestones.
- Student cannot create milestones.
- Completion recalculates plan progress.
- Sort order is stored.
- Milestones soft delete for history.
- Does not create homework automatically.
- Activity logging exists.

Progress reviews:

- Belong to a learning plan and student.
- Can reference instructor.
- Assigned instructor/admin can create through service.
- Student can view own review.
- Unrelated users cannot view unrelated reviews.
- Completed plans cannot accept new review.
- Review creation clears `review_due` back to active.
- Does not create homework or public review-engine records.
- Activity logging exists.

Adjustments:

- Dedicated `learning_plan_adjustments` table exists.
- Captures `changed_by`, `change_reason`, previous values, new values, and timestamp.
- Reason is required by service.
- Major supported plan fields are not silently overwritten through service.
- Completed/archived plans reject adjustment through service.
- Sensitive educational notes are not duplicated into activity properties.

## Services / Actions Audit

`LearningPlanService` is cohesive and acceptable for Phase 4. It handles draft creation, instructor assignment, assessments, activation, adjustments, milestones, milestone completion/progress calculation, reviews, review-due state, completion, archiving, owner checks, instructor assignment checks, admin permission checks, validation, transactions for multi-write operations, and audit logging.

Controllers are thin and return views. Livewire components call the service for writes. Dashboard integration remains read-only.

Risk: Filament create/edit pages currently use resource CRUD rather than service actions for all critical changes. This is the main reason for "PROCEED WITH CAUTION".

## Frontend Audit

Student UI:

- Can view active plans, historical plans, assigned instructor, current focus, milestones, assessments, reviews, and empty state.
- Can start a draft plan from own learning goal.
- Cannot access another student's plans through policy/service routes tested at feature level.
- Cannot assign instructor, edit instructor notes, create milestones, create reviews, or create booking/payment/homework from the plan.

Instructor UI:

- Can view assigned student plans.
- Can record assessment, create milestones, and create progress reviews through the service.
- Service blocks unrelated instructor access.
- Dashboard aggregate counts are not yet surfaced; this is non-blocking.

## Admin / Filament Audit

Confirmed:

- No `StudentResource` or `InstructorResource` duplicate was created.
- `StudentLearningPlanResource` manages learning plan records only.
- Policy registration exists.
- Shield-style permissions are seeded.
- Table filters exist for status, subject, and instructor.
- Sensitive long-form notes are not displayed as table columns.
- No unsafe bulk review/action path exists.

Risk:

- Generic create/edit pages can bypass service adjustment history and lifecycle transition methods. This should be hardened with service-backed admin actions before Phase 5 if Phase 5 depends on heavy admin plan operations.

## Dashboard Integration Audit

Student dashboard read-only additions:

- Active learning plan count.
- Current active plan summary.
- Current progress percent.
- Milestone/instructor summary through loaded relationships.

The dashboard does not create learning plan, booking, payment, wallet, meeting, homework, or review records.

Instructor dashboard read-only aggregate counts were not added; instructor management UI exists separately.

## Out-Of-Scope Boundary Audit

Confirmed Phase 4 did not expand:

- Availability engine.
- Booking engine.
- Recurring lesson scheduling.
- Wallet ledger.
- Payment processing.
- Meeting engine.
- Homework engine.
- Public reviews engine.
- Referral engine.
- AI recommendations.
- Parent accounts.
- Packages/subscriptions.

Existing booking, payment, and homework files appear in duplicate searches because they predate Phase 4 and are read by existing student dashboard services. Phase 4 did not add booking/payment/homework records or workflows.

## Permissions / Policies Audit

Verified:

- Student can view own plans.
- Student cannot access another student's plan.
- Student can create a draft plan only from own learning goal, unless admin permission applies.
- Student cannot edit instructor assessment/review notes through service.
- Assigned instructor can view/manage assigned plans through service actions.
- Unrelated instructor is denied.
- Admin access requires permissions.
- Guest route access is denied.
- Completed/archived write restrictions are enforced in service.

Weakness:

- Child `create` policy methods are role/permission-level; assignment checks happen in the service. This is acceptable while all writes go through service, but policy methods should be expanded if direct child resources are added.

## Activity Logging Audit

Verified logged events:

- `learning_plan_created`
- `learning_plan_instructor_assigned`
- `learning_plan_assessment_recorded`
- `learning_plan_activated`
- `learning_plan_review_due`
- `learning_plan_milestone_created`
- `learning_plan_milestone_completed`
- `learning_plan_progress_review_created`
- `learning_plan_adjusted`
- `learning_plan_completed`
- `learning_plan_archived`

Actor and subject are recorded through `AuditTrailService`. Adjustment reason is stored in `learning_plan_adjustments`. Activity properties contain IDs/status metadata and avoid dumping full sensitive educational notes.

## Test Coverage

Phase 4 test file:

- `tests/Feature/Student/LearningPlanFoundationTest.php`

Coverage mapping:

| Requirement | Status |
|---|---|
| Student can create draft from own active goal | Covered |
| Student cannot create from another student's goal | Covered |
| Only one open plan per goal | Covered |
| Uses Subject master data | Covered |
| Does not create booking/payment/homework records | Covered |
| Approved/active instructor can be assigned | Covered |
| Non-bookable instructor rejected | Covered |
| Unrelated instructor denied | Covered |
| Assigned instructor records assessment | Covered |
| Student views own assessment | Covered |
| Other student cannot view assessment | Covered |
| Public assessment exposure | Weak; no public route exists |
| Instructor/admin creates milestone | Covered for instructor |
| Student views milestone | Covered |
| Student cannot create milestone | Covered |
| Milestone completion updates progress | Covered |
| Assigned instructor creates progress review | Covered |
| Student views own progress review | Covered |
| Other users cannot view unrelated review | Covered |
| Completed plan rejects review | Covered |
| Adjustment history captured | Covered |
| Adjustment reason required | Covered |
| Completed/archived edit restrictions | Covered |
| Draft to active | Covered |
| Active to review_due | Covered |
| Complete and archive lifecycle | Covered |
| Historical plans preserved | Covered |
| Student dashboard summary | Covered by full Phase 3/4 suite; could be stronger |
| Dashboard does not create out-of-scope records | Covered |
| Instructor dashboard summary | Missing; non-blocking |
| Admin needs permission | Covered indirectly by policies/resources; could be stronger |
| Public/guest denied | Covered |
| Phase 3 student tests pass | Covered by full suite |
| Phase 2 instructor tests pass | Covered by full suite |
| Subject reconciliation tests pass | Covered by full suite |
| Booking tests pass | Covered by full suite |
| No duplicate tables | Covered |

## Documentation Audit

`docs/architecture/phase-4-learning-plan-foundation.md` exists and accurately documents:

- Learning plan philosophy.
- Why plans are not auto-created.
- Reused learning goal foundation.
- Lifecycle.
- Assessment model.
- Milestone model.
- Progress review model.
- Adjustment/versioning strategy.
- Permissions.
- Dashboard integration.
- Admin resources.
- Intentionally excluded booking/payment/homework/AI behavior.
- Future integration points.

## Commands

| Command | Result |
|---|---|
| `php artisan migrate` | Passed; nothing to migrate |
| `php artisan test tests/Feature/Student/LearningPlanFoundationTest.php` | Passed: 10 tests, 44 assertions |
| `php artisan test` | Passed: 1798 tests, 3848 assertions |
| `php artisan migrate:status` | Passed; Phase 4 migrations ran in batch 34 |
| `php artisan route:list` | Passed; 217 routes |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Not run; no JS/CSS asset files changed |

## Duplicate Prevention Search

| Term | Classification |
|---|---|
| `student_learning_plans` | Valid Phase 4 table |
| `learning_plan_assessments` | Valid Phase 4 table |
| `learning_plan_milestones` | Valid Phase 4 table |
| `learning_plan_reviews` | Valid academic progress review table; not public reviews engine |
| `learning_plan_adjustments` | Valid Phase 4 adjustment history |
| `student_learning_goals` | Valid Phase 3 foundation reused |
| `students` | No table/model duplicate found |
| `student_profiles` | No table/model duplicate found |
| `instructors` | No table/model duplicate found |
| `instructor_profiles` | No table/model duplicate found |
| `bookings` | Existing booking module only; not expanded by Phase 4 |
| `wallets` | No wallet table found |
| `payments` | Existing payment settings/webhooks/booking payment code only; not expanded by Phase 4 |
| `homework` | Existing homework module only; not expanded by Phase 4 |
| `reviews` | Existing route placeholders plus Phase 4 learning plan reviews; no public review-engine expansion |
| `subjects` | Valid academic master table |
| `teacher_subjects` | Valid instructor subject compatibility table |
| `user_profiles` | Valid shared profile table |
| `users` | Valid identity table |

## Recommended Fixes Before Phase 5

1. Convert Filament learning plan lifecycle/admin changes to explicit service-backed actions, or make generic edit fields read-only where they bypass adjustment history.
2. Add focused admin permission tests for `StudentLearningPlanResource`.
3. Add browser/Livewire tests for student and instructor learning plan pages.
4. Add instructor dashboard read-only counters for assigned active plans, review due, and pending assessment.
5. Consider an application-level guard or generated column strategy if duplicate open plans per goal becomes a concurrency concern.

## Recommended Next Phase

Because the foundation is strong but has admin/UI hardening gaps, the recommended decision is **PROCEED WITH CAUTION** rather than full SAFE.

Recommended next phase after addressing the non-blocking admin hardening item:

**Phase 5 — Marketplace Discovery Foundation**

Do not start availability or booking expansion yet.
