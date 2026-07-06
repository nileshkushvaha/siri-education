# Phase 3.1 Strict Student Foundation Audit

## Executive Decision

Readiness score: **94/100**

Decision: **SAFE TO PROCEED TO PHASE 4**

Recommended next phase: **Phase 4 — Learning Plan Foundation**

Phase 3 successfully establishes the student foundation without duplicating identity, profile, subject, booking, wallet, payment, homework, or review concepts. It reuses `users`, `user_profiles`, `subjects`, `academic_levels`, `languages`, existing profile/avatar conventions, existing dashboard shell, existing Wishlist route surface, Spatie Permission, Filament policy conventions, and `AuditTrailService`.

The original audit concern was valid: the Phase 3 migrations were pending in the local app database after implementation. During this audit, all four Phase 3 migrations were applied successfully and `migrate:status` now shows them as ran in batch 33.

## Blocking Issues

None after audit migration fix.

## Non-Blocking Issues

1. **Direct self-favorite test is weak.** The service blocks self-favorites, but current tests assert non-bookable rejection and duplicate behavior more directly than the self-favorite branch.
2. **Dashboard isolation is mostly covered by ownership queries and route tests, not a deep cross-user dashboard fixture.** Service queries are scoped to the authenticated user.
3. **Rollback safety was verified by migration inspection, not by running a destructive local rollback.** The down methods are straightforward, but running rollback would delete any local Phase 3 data.
4. **Favorite buttons are on instructor profile pages only.** Marketplace/card-level favorite controls are intentionally deferred.
5. **Learning goals are a foundation, not a Learning Plan engine.** This is correct for Phase 3 and should be handled in Phase 4.

## Files Created In Phase 3

| File | Purpose | Necessary | Similar Existing File | Duplicate Risk | Architecture |
|---|---|---:|---|---|---|
| `app/Enums/LearningGoalStatus.php` | Controlled goal lifecycle. | Yes | None for student goals. | Low | Good |
| `app/Enums/LearningGoalType.php` | Controlled goal categories and academic-level rule. | Yes | None. | Low | Good |
| `app/Models/StudentLearningGoal.php` | Student-owned learning goal domain model. | Yes | None. | Low | Good |
| `app/Models/StudentFavoriteInstructor.php` | Pivot model for favorite instructors. | Yes | Wishlist was placeholder only. | Low | Good |
| `app/Services/Student/StudentProfilePreferenceService.php` | Student preference updates and audit logging. | Yes | ProfileService exists but not student preference-specific. | Low | Good |
| `app/Services/Student/StudentLearningGoalService.php` | Goal lifecycle, validation, owner checks, logging. | Yes | None. | Low | Good |
| `app/Services/Student/StudentFavoriteInstructorService.php` | Favorite/unfavorite eligibility and logging. | Yes | None. | Low | Good |
| `app/Services/Student/StudentDashboardService.php` | Student dashboard aggregation. | Yes | Existing booking/homework services are reused. | Low | Good |
| `app/Policies/StudentLearningGoalPolicy.php` | Goal authorization. | Yes | UserEducation/UserExperience policies. | Low | Good |
| `app/Policies/StudentFavoriteInstructorPolicy.php` | Favorite authorization. | Yes | None. | Low | Good |
| `app/Livewire/Frontend/Student/LearningGoals.php` | Thin frontend goal component. | Yes | Other student dashboard components. | Low | Good |
| `app/Livewire/Frontend/Student/FavoriteInstructors.php` | Thin frontend favorites component. | Yes | Existing Wishlist placeholder. | Low | Good |
| `app/Http/Controllers/Student/StudentLearningGoalController.php` | Thin page controller. | Yes | Other student page controllers. | Low | Good |
| `app/Http/Controllers/Student/StudentFavoriteInstructorController.php` | Thin favorite action controller. | Yes | None. | Low | Good |
| `app/Filament/Resources/StudentLearningGoals/*` | Admin management of goal records only. | Yes | Other domain resources. | Low; not StudentResource | Good |
| `database/migrations/2026_07_11_100000_add_student_preference_fields_to_user_profiles_table.php` | Adds missing student profile preference FKs. | Yes | Existing `user_profiles`. | Low | Additive |
| `database/migrations/2026_07_11_100100_create_student_preferred_subjects_table.php` | Normalized preferred-subject pivot. | Yes | No prior relational preference storage. | Low | Good |
| `database/migrations/2026_07_11_100200_create_student_learning_goals_table.php` | Learning goal storage. | Yes | None. | Low | Good |
| `database/migrations/2026_07_11_100300_create_student_favorite_instructors_table.php` | Favorite instructor storage. | Yes | Wishlist had no table/model. | Low | Good |
| `database/seeders/StudentPermissionSeeder.php` | Seeds Shield-style permissions. | Yes | Other permission seeders. | Low | Good |
| `resources/views/student/learning-goals/index.blade.php` | Learning goals page shell. | Yes | Other student pages. | Low | Good |
| `resources/views/student/favorite-instructors/index.blade.php` | New view for reused Wishlist surface. | Yes | Wishlist placeholder. | Low | Good |
| `resources/views/livewire/frontend/student/learning-goals.blade.php` | Goal UI. | Yes | Other Livewire views. | Low | Good |
| `resources/views/livewire/frontend/student/favorite-instructors.blade.php` | Favorite instructor UI. | Yes | Wishlist placeholder. | Low | Good |
| `tests/Feature/Student/StudentProfilePreferenceTest.php` | Preference tests. | Yes | None. | None | Good |
| `tests/Feature/Student/StudentLearningGoalTest.php` | Goal lifecycle tests. | Yes | None. | None | Good |
| `tests/Feature/Student/StudentFavoriteInstructorTest.php` | Favorite tests. | Yes | None. | None | Good |
| `tests/Feature/Student/StudentDashboardFoundationTest.php` | Dashboard/resource/duplicate-prevention tests. | Yes | Student route tests. | None | Good |
| `docs/architecture/phase-3-student-profile-learning-goals-dashboard.md` | Architecture record. | Yes | Phase docs. | None | Good |

## Files Modified

| File | Change | Why | Backward-Compatible | Affects Out-of-Scope Flows |
|---|---|---|---:|---|
| `app/Models/User.php` | Added goals, preferred subjects, favorites relationships. | Shared identity owns student records. | Yes | No booking/payment changes |
| `app/Models/UserProfile.php` | Added student preference fillables and relationships. | Reuse shared profile. | Yes | No instructor breakage |
| `app/Models/Subject.php` | Added preferred student and goal relationships. | Master data linkage. | Yes | Subject reconciliation unaffected |
| `app/Models/AcademicLevel.php` | Added student profile/goal relationships. | Master data linkage. | Yes | No booking changes |
| `app/Providers/AppServiceProvider.php` | Registered new policies. | Authorization. | Yes | No portal change |
| `app/Http/Controllers/Profile/ProfileController.php` | Loads student preference data for profile UI. | Profile edit support. | Yes | Profile flow preserved |
| `app/Http/Requests/Profile/UpdateProfileRequest.php` | Validates student preference inputs. | Server-side validation. | Yes | No instructor-only regression |
| `app/Services/Profile/ProfileService.php` | Delegates preference writes to student service. | Keep service logic centralized. | Yes | Existing profile update still works |
| `resources/views/profile/show.blade.php` | Adds student preference controls for students. | Self-service preferences. | Yes | Instructor/admin profile behavior preserved |
| `app/Livewire/Frontend/Student/DashboardOverview.php` | Delegates aggregation to `StudentDashboardService`. | Thin component. | Yes | Existing booking/homework read summaries reused |
| `resources/views/livewire/frontend/student/dashboard-overview.blade.php` | Adds profile/goals/subjects/favorites/safe placeholders. | Dashboard foundation. | Yes | No new records in out-of-scope modules |
| `routes/web.php` | Adds learning goal and favorite routes. | Frontend Phase 3 pages/actions. | Yes | Existing `dashboard.wishlist` preserved |
| `app/Services/Account/AccountMenuService.php` | Adds Learning Goals and renames Wishlist label to Favorite Instructors. | Match approved reuse decision. | Yes | No portal logic duplicated |
| `app/Http/Controllers/Student/StudentWishlistController.php` | Points existing wishlist route to favorite instructor page. | Backward-compatible route reuse. | Yes | No wishlist table duplicate |
| `resources/views/student/wishlist/index.blade.php` | Reworked placeholder to favorites. | Compatibility view. | Yes | No course wishlist table |
| `resources/views/instructors/show.blade.php` | Adds favorite/unfavorite button for students. | Entry point for favorites. | Yes | Public visibility still controller/service gated |
| `app/Filament/Resources/Users/Schemas/UserForm.php` | Adds read-only student overview. | Non-duplicative admin summary. | Yes | No StudentResource |
| `database/seeders/DatabaseSeeder.php` | Calls student permission seeder. | Standard seed flow. | Yes | Seed-only |
| `tests/Feature/Student/StudentRoutesTest.php` | Updates Wishlist text expectation. | Label changed to favorites. | Yes | Tests only |

## Migration Audit

### `2026_07_11_100000_add_student_preference_fields_to_user_profiles_table`

- Table: `user_profiles`
- Adds: `student_academic_level_id` nullable UUID FK, `student_preferred_language_id` nullable FK
- FK behavior: academic level/language `nullOnDelete`
- Rollback: drops both FKs/columns
- Production data risk: low; nullable/additive
- Duplicate risk: none; reuses shared profile

### `2026_07_11_100100_create_student_preferred_subjects_table`

- Table: `student_preferred_subjects`
- Columns: `user_id`, `subject_id`, timestamps
- FK behavior: `user_id` cascade, `subject_id` restrict
- Unique: `user_id + subject_id`
- Rollback: drops pivot table
- Production data risk: low; new preference table
- Duplicate risk: low; no prior wishlist/preferred subject table existed

### `2026_07_11_100200_create_student_learning_goals_table`

- Table: `student_learning_goals`
- Columns: owner, `subject_id`, nullable `academic_level_id`, title, type, description, target date, priority, status, completed/archived timestamps, actor columns, timestamps, soft deletes
- FK behavior: `user_id` cascade, `subject_id` restrict, `academic_level_id` null, actor FKs null
- Indexes: `user_id/status`, `subject_id/status`
- Rollback: drops table
- Production data risk: low for creation; rollback would intentionally delete goal data
- Duplicate risk: none found

### `2026_07_11_100300_create_student_favorite_instructors_table`

- Table: `student_favorite_instructors`
- Columns: id, `student_user_id`, `instructor_user_id`, timestamps
- FK behavior: both cascade on user delete
- Unique: `student_user_id + instructor_user_id`
- Rollback: drops table
- Production data risk: low for creation; rollback deletes favorites
- Duplicate risk: low; existing Wishlist was route/view placeholder only

Confirmed:

- No `students` table.
- No `student_profiles` table.
- No duplicate wishlist/favorites table.
- `user_profiles.user_id` uniqueness remains preserved from Phase 1.
- Phase 3 migrations are applied locally: batch 33.

## Student Profile Audit

Profile architecture is reused correctly. Student-specific preferences are stored on `user_profiles` only where they are one-to-one profile attributes, while preferred subjects are normalized through a pivot. Existing country/timezone/language/profile photo fields are reused. Avatar still uses the existing Media Library `avatar` collection.

Profile update remains compatible: `ProfileService` handles generic profile updates and delegates student preference writes to `StudentProfilePreferenceService`. Tests cover preference update and activity logging. Profile routes remain authenticated and frontend-portal protected.

Risk: the preference service itself accepts a `User` argument and does not independently authorize it; current callers pass `auth()->user()`. This is acceptable today but should stay controller/service-bound.

## Preferred Subjects Audit

Confirmed:

- Uses `student_preferred_subjects` pivot.
- FK integrity to `subjects`.
- Duplicate subject preferences blocked by unique constraint and service deduplication.
- Inactive/archived subjects rejected through `Subject::availableForAssignment()`.
- Dashboard reads `preferredSubjects`.
- No free-text subject input was introduced.
- No `student_preferred_subject_ids` JSON column exists.

## Learning Goals Audit

Confirmed:

- Single `StudentLearningGoal` model/table.
- Uses `Subject` master data only; no free-text subject field.
- Uses `AcademicLevel` conditionally through service validation.
- Statuses: draft, active, paused, completed, archived.
- Types: academic, exam_preparation, professional, personal, skill_development, other.
- Active dashboard scope excludes completed/archived goals.
- Historical goals are preserved via status plus soft deletes.
- Subject FK restricts delete, avoiding cascade deletion of academic history.
- Owner checks are enforced in service.
- Activity logging exists for create/update/complete/archive.

## Academic Level Validation

The approved conditional rule is implemented:

- Required for `academic` and `exam_preparation`.
- Optional for professional, personal, skill development, and other.
- Inactive/archived academic levels rejected through `AcademicLevel::availableForAssignment()`.
- Dashboard displays related level when present.

Coverage is good for exam-preparation required and personal optional. Direct per-type tests for every optional type are not present but the enum method is simple and centralized.

## Favorite Instructors / Wishlist Audit

Confirmed:

- Existing `dashboard.wishlist` route is preserved and now renders Favorite Instructors.
- No `wishlists` table/model was created.
- `student_favorite_instructors` is justified because the existing Wishlist was only a placeholder route/view, not instructor favorites.
- Students can favorite only active, public, bookable instructors.
- Duplicate favorites are prevented by service `firstOrCreate` and DB unique constraint.
- Removal works.
- Non-bookable instructors remain in the table but are excluded from bookable favorite lists.
- Instructor profile favorite button posts to dashboard favorite routes.
- Menu label is Favorite Instructors while route name remains backward-compatible.

Risk: direct self-favorite branch should get its own focused test.

## Dashboard Audit

Confirmed:

- Dashboard aggregation uses `StudentDashboardService`.
- `DashboardOverview` Livewire component stays thin.
- Shows profile completion, active goals, preferred subjects, favorite summary, existing booking/homework summaries, and safe placeholders for wallet/payments/meetings.
- Does not create records for wallet/payment/meeting/homework/review modules.
- Does not call unavailable wallet/payment/meeting services.
- Reads authenticated student's own relationships.
- Recommended next action precedence matches the approved plan.

Existing booking/homework services are read for already-existing dashboard stats; this is intentional reuse, not Phase 3 expansion.

## Out-Of-Scope Boundary Audit

No expansion found for:

- Booking engine
- Availability engine
- Wallet ledger
- Payment processing
- Meeting engine
- Homework engine
- Reviews engine
- Referral engine
- Full Learning Plan engine

Touched related surfaces:

- Dashboard reads existing booking/homework summaries.
- Student menu still contains existing booking/payment/homework/review placeholder/routes.
- Instructor profile adds favorite action only.

## Admin / Filament Audit

Confirmed:

- No `StudentResource` was created.
- `UserResource` remains the student identity/profile admin surface.
- `StudentLearningGoalResource` manages goals only.
- Policies are registered.
- Shield-style permissions are seeded through `StudentPermissionSeeder`.
- Tests prove non-permitted manager cannot view goal resource until permission is granted.
- No favorite instructor resource was created.
- No unsafe bulk review/identity action added.

Risk: `StudentLearningGoalResource` exposes normal delete actions. The model uses soft deletes, and this is acceptable with permissions, but later audit may prefer archive-only admin action.

## Services / Actions Audit

| Service | Responsibility | Transactions | Validation | Owner/Auth Checks | Logging |
|---|---|---:|---:|---:|---:|
| `StudentProfilePreferenceService` | Profile preference and preferred subject sync | Yes | Yes | Caller-scoped | Yes |
| `StudentLearningGoalService` | Goal lifecycle | Yes | Yes | Yes | Yes |
| `StudentFavoriteInstructorService` | Favorite/unfavorite and bookability checks | Yes | Yes | Yes | Yes |
| `StudentDashboardService` | Read-only dashboard aggregation | N/A | N/A | User-scoped query | N/A |

Controllers and Livewire components remain thin and delegate business rules to services.

## Frontend / Livewire Audit

Confirmed:

- Routes are inside authenticated, email-conditional, active-account, password-change, session-track, frontend-portal middleware group.
- Guests are redirected from student pages.
- Learning goals page works.
- Favorites page works.
- Validation messages are present in Livewire forms.
- Components call services for business actions.
- No hidden wallet/payment/meeting dependency.

## Activity Logging Audit

Logged through `AuditTrailService`:

- `student_preferences_updated`
- `student_learning_goal_created`
- `student_learning_goal_updated`
- `student_learning_goal_completed`
- `student_learning_goal_archived`
- `student_favorite_instructor_added`
- `student_favorite_instructor_removed`

Logged metadata is limited to safe IDs, counts, status/type context, and no sensitive profile text.

## Permissions / Policies Audit

Confirmed:

- Students manage own learning goals through owner-scoped service queries.
- Students manage own favorites through service queries.
- Students cannot favorite non-bookable instructors.
- Students cannot favorite self by service validation.
- Public users cannot access dashboard routes.
- Admin goal management requires permissions.

Risk: add direct self-favorite denial test.

## Tests Audit

Added/updated Phase 3 tests:

- `StudentProfilePreferenceTest`
- `StudentLearningGoalTest`
- `StudentFavoriteInstructorTest`
- `StudentDashboardFoundationTest`
- `StudentRoutesTest`

Coverage status:

| Requirement | Status |
|---|---|
| Profile row exists safely | Covered indirectly |
| Profile/preferences update | Covered |
| Preferred subjects pivot integrity | Covered |
| Duplicate preferred subject prevented | Covered |
| Invalid/inactive subject rejected | Covered |
| Learning goal create/update/archive/complete | Covered |
| No free-text subject in goals | Covered by schema/service |
| Academic level validation | Covered |
| Another student cannot edit goal | Covered |
| Archived goals excluded from active dashboard | Covered by scope/service; could use deeper dashboard fixture |
| Historical goals preserved | Covered by completed/archived states |
| Dashboard access | Covered |
| Dashboard data isolation | Weak but service scoped |
| Dashboard safe empty states | Covered in UI/service, not deep assertion |
| Favorite approved/bookable instructor | Covered |
| Reject non-bookable instructor | Covered |
| Reject self-favorite | Weak direct coverage |
| Duplicate favorite blocked | Covered |
| Favorite removal | Covered |
| Favorites appear on dashboard | Covered through service/dashboard fields |
| Admin permission checks | Covered |
| No duplicate student/profile/favorite tables | Covered |
| Instructor onboarding tests still pass | Covered by full suite |
| Subject reconciliation tests still pass | Covered by full suite |
| Booking tests still pass | Covered by full suite |

## Documentation Audit

`docs/architecture/phase-3-student-profile-learning-goals-dashboard.md` exists and documents:

- Reused user/profile architecture
- Student profile preference strategy
- Preferred subject pivot decision
- Learning goal lifecycle
- Favorite instructor/Wishlist decision
- Dashboard widgets and empty states
- Admin and permissions
- Audit logging
- Intentionally excluded modules
- Future Learning Plan boundary
- Duplicate prevention decisions

It is accurate after this audit.

## Commands

| Command | Result |
|---|---|
| `php artisan migrate` | Passed; four Phase 3 migrations applied |
| `php artisan test` | Passed: 1788 tests, 3796 assertions |
| `php artisan migrate:status` | Passed; Phase 3 migrations ran in batch 33 |
| `php artisan route:list` | Passed; 212 routes |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Not run; no JS/CSS asset files changed |

## Duplicate Prevention Search

| Term | Classification |
|---|---|
| `students` | Valid role/user references; no `students` table/model |
| `student_profiles` | Valid audit/doc references only; no table/model |
| `student_learning_goals` | Valid new Phase 3 table/model/resource |
| `learning_goals` | Valid route/resource/service naming |
| `student_favorite_instructors` | Valid new Phase 3 favorite table |
| `wishlist` | Valid backward-compatible route/surface now labeled Favorite Instructors |
| `wishlists` | No duplicate table/model found |
| `favorite_instructors` | Valid route/service naming |
| `student_preferred_subjects` | Valid normalized pivot |
| `preferred_subjects` | Valid relationship/UI naming |
| `subjects` | Valid academic master table |
| `user_profiles` | Valid shared profile table |
| `users` | Valid identity table |
| `wallets` | No wallet ledger/table expansion found |
| `bookings` | Existing booking engine only; dashboard read reuse |
| `payments` | Existing payment routes/settings only; no Phase 3 expansion |
| `homework` | Existing homework module only; dashboard read reuse |
| `reviews` | Existing placeholder routes/views only; no Phase 3 expansion |

## Final Decision

Score: **94/100**

Decision: **SAFE TO PROCEED TO PHASE 4**

Phase 4 may start because migrations are applied, full tests pass, no duplicate student/profile/favorite concepts exist, learning goals work, favorites work, dashboard works, and out-of-scope modules were not expanded.

Recommended next phase: **Phase 4 — Learning Plan Foundation**.
