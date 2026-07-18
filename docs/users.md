# Users

## Model

`App\Models\User` — standard Laravel authenticatable, implements `FilamentUser`, `MustVerifyEmail`.

Traits: `HasFactory`, `HasRoles` (Spatie), `LogsActivity` (Spatie), `Notifiable`.

Key fields: `name`, `first_name`, `last_name`, `email`, `status`, `email_verified_at`, `locked_until`, `totp_secret`, `totp_recovery_codes`, `password_changed_at`, `force_password_change`.

Status values: `active`, `inactive`, `pending`, `blocked`. Constant: `User::STATUS_ACTIVE`.

## Filament Resource

`app/Filament/Resources/Users/` — Administration group, sort 1.

Pages: `ListUsers`, `CreateUser`, `EditUser`.

`CreateUser` and `EditUser` log to `activity('users')` after save.

Role assignment in `EditUser` triggers `roles_updated` activity event and sends admin bell notification.

`EditUser` carries no instructor-onboarding content — no "Instructor"
tab, no lifecycle review actions, no Experience/Education relation
managers. All of that lives solely on
`app/Filament/Resources/InstructorOnboarding/` (nav group "Instructor")
so admins reviewing applications aren't hunting through general user
data. The two resources share code, not routes:
`UserForm::instructorProfileSchema()` was removed and its fields moved
into `InstructorOnboardingForm`; the eleven lifecycle actions (Start
Review, Approve, Reject, Force Approve, Activate, vacation/suspend/
archive, ...) live in the `App\Filament\Concerns\
HasInstructorLifecycleActions` trait — `EditInstructorOnboarding` is
its only consumer now, but the trait exists precisely so a second page
never has to redefine these actions from scratch.
`ExperiencesRelationManager` and `EducationsRelationManager` moved to
`InstructorOnboardingResource` entirely (they were onboarding-only
data). `ActivityLogRelationManager` is the one relation manager kept on
**both** resources — general account activity is relevant on the plain
Users page too, not just during instructor review.

`InstructorOnboardingResource`'s query is scoped to `role('instructor')`
— a non-instructor's record does not exist through this resource (404,
not a hidden action). Its navigation badge counts applications in
`InstructorStatus::needsReview()` (Submitted, UnderReview,
DocumentsPending, InterviewRequired). Both `canViewAny()` and
`canEdit()` are gated on `InstructorOnboardingService::
canReviewApplications()` — the same permission (`instructor.
applications.review`, with an `Update:User` fallback) that already
governed the review actions before the split.

### Instructor workspace access

`App\Http\Middleware\EnsureInstructorWorkspaceAccess` gates the
frontend instructor **teaching workspace** routes (lessons,
availability, students, homework, quality-insights, analytics,
earnings, settlements, payout-methods, withdrawals, vacation — the
`dashboard.instructor.*` group in `routes/web.php`, excluding
onboarding/start/submit) behind `InstructorStatus::publiclyVisible()`
(Approved/Active/Vacation). An instructor outside that set — still
mid-application, or suspended/archived/rejected — is redirected to
`dashboard.instructor.onboarding` instead. `AccountMenuService` applies
the same check to hide the Teach/Performance/Money sidebar groups for
the same instructors, showing only Dashboard + Application Status +
Account. The instructor dashboard (`/dashboard` itself) is deliberately
**not** gated by this middleware — it already renders a status-first
"Profile readiness" card for non-eligible instructors inline (Phase
23I), and redirecting it away would contradict that existing, tested
behavior.

## Account approval workflow

When `RegistrationSettings::require_approval` is true, new registrations land in `pending` status. Admin changes status to `active` via `EditUser`, which dispatches `UserApproved` → `SendApprovalNotification` sends the approval email.

## Force password change

`User::$force_password_change = true` redirects the user to a change-password page on every login until they comply. Set by admins via `EditUser`.

`EnsurePasswordChangeRequired` middleware enforces this at the Filament panel level.

## Activity log events

| Event | Log name |
|---|---|
| User created | `users` |
| Roles updated | `users` |
| Account approved | `users` |
| Password change required set | `users` |

## Policy

`App\Policies\ProfilePolicy` — governs profile view/update/password change for the current user.

Role and permission management is governed by Filament Shield's auto-generated permissions.
