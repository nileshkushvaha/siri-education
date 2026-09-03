# Users

## Model

`App\Models\User` — standard Laravel authenticatable, implements `FilamentUser`, `MustVerifyEmail`.

Traits: `HasFactory`, `HasRoles` (Spatie), `LogsActivity` (Spatie), `Notifiable`.

Key fields: `name`, `first_name`, `last_name`, `email`, `status`, `email_verified_at`, `locked_until`, `totp_secret`, `totp_recovery_codes`, `password_changed_at`, `force_password_change`.

Status values: `active`, `inactive`, `pending`, `blocked`. Constant: `User::STATUS_ACTIVE`.

## Filament Resource

`app/Filament/Resources/Users/` — People group, label "All Users", sort 1.

Pages: `ListUsers`, `CreateUser`, `EditUser`.

### Role-scoped user lists

People → Users offers three entries into the same table:

| Nav label | Resource | Scope |
|---|---|---|
| All Users | `UserResource` (`/admin/users`) | every user |
| Students | `App\Filament\Resources\Users\StudentResource` (`/admin/students`) | `whereHasRoleNamed('student')` |
| Instructors | `App\Filament\Resources\Users\InstructorResource` (`/admin/instructors`) | `whereHasRoleNamed('instructor')` |

They live in the `Users` namespace, not `Resources\Students` /
`Resources\Instructors`: `StudentDashboardFoundationTest` guards against
a parallel student identity domain and asserts no
`App\Filament\Resources\Students\StudentResource` exists. These are views
of `User`, not a second identity, so the `Users` namespace is both
accurate and clear of that guard.

The two scoped resources exist so the roles admins work with daily are
one click away instead of a role filter re-applied every visit. They are
**read/route surfaces only**: index page, `canCreate()` false, no form,
and their row View/Edit actions link to `UserResource`'s pages — so
`EditUser` (and the audit logging hanging off it) stays the single write
path per user. Both target the `User` model, so Shield's model-subject
permissions (`View:User`, …) and `UserPolicy` govern them with no extra
permission rows.

Each resource sets its own `$modelLabel` / `$pluralModelLabel` (the model
is `User`, so otherwise every heading, breadcrumb and empty state would
read "Users"): "all users", "student", "instructor".

Columns come from `RoleScopedUsersTable`, which reuses
`UsersTable::configure()` for sorting/actions and replaces only the
columns: no "Roles" (the list is already one role), and the role's own
lifecycle status shown outright rather than toggle-hidden.

### List columns and search

`UsersTable` is the one column set all three lists build on.

- **Person** — name with the email as its subtitle. Two columns for "who
  is this" pushed Mobile and status off the fold.
- **Mobile** — `phone_e164`, falling back to the raw `phone` entry.
- **Country**, **Roles** (All Users only), **Joined**, Profile %.
- **Account access** (`users.status`) — can this person sign in.
  Email confirmation is this column's subtitle, not a separate badge.
- **Instructor / Student lifecycle** (`user_profiles.*_status`) — where
  they are in that role's lifecycle. Both columns carry a tooltip saying
  they are a different axis from account access.

Three status columns (account / email-verified / role lifecycle) each
had a state called "Active" or "Verified" meaning something different,
which is why account access absorbed the email badge and the remaining
two are labelled and tooltipped as distinct axes. `User::statusLabel()`,
`statusColor()` and `statusesMatching()` are the single source for
account-status presentation — `RecentUsersWidget` had been drifting with
its own copy.

There is no filter panel on any of the three lists. Five dropdowns
(role, status, instructor status, student status, verified) asked a
question per dropdown for what is nearly always "find this one person".
Search does that work instead, and reaches further than the filters did:
name, email, mobile (across `phone_e164`, `phone` and
`phone_national_number`, so any pasted format matches), country, role
name, and the *labels* of both account status and the role lifecycle —
typing "pending" or "under review" narrows the list the way choosing a
filter used to. Label-to-value mapping lives in `User::statusesMatching()`
and `App\Filament\Resources\Users\Tables\LifecycleSearch`. Search is
debounced and persisted in the session; each list's placeholder names
what it accepts.

The Posts column is gone from every user list — authoring isn't these
users' primary work.

`App\Filament\Resources\Users\Tables\UserColumns` is the shared column
factory behind all of this (`person()`, `mobile()`, `country()`,
`accountAccess()`, `instructorLifecycle()`, `studentLifecycle()`). Any
list of people uses it rather than redefining the cells — a tooltip or
search rule fixed on one screen and not the others is the failure mode
it exists to prevent. `instructorLifecycle()`/`studentLifecycle()` take
the attribute name because the onboarding list reads a joined
`instructor_status` column while the others read it through the
`profile` relation.

### Instructor onboarding list

`InstructorOnboardingTable` now builds its Instructor, Mobile and status
cells from `UserColumns` too, and its status **filter** is gone: the
page's tabs (Needs Review / Approved & Active / Rejected, Suspended &
Archived / All) already triage by status, and search matches a status by
label — the dropdown was a third way to ask the same question.

`InstructorOnboardingResource` sets `$modelLabel = 'instructor
application'` and `$pluralModelLabel = 'instructor onboarding'`; the
model is `User`, so the page heading and breadcrumb read "Users" without
them.

### Document requirements seeding

`InstructorDocumentRequirementSeeder` seeds one row per
`App\Enums\InstructorEvidenceCollection` case — it iterates the enum
rather than keeping its own list, and
`InstructorDocumentRequirementSeederTest` fails if a case has no row, so
a new evidence type cannot ship invisible to admins.

All six are seeded `required = true` and `active = true`. The five KYC
documents accept image/PDF up to 4 MB; `introduction_video` accepts
MP4/WebM/MOV up to 50 MB, matching both its `UserProfile` media
collection and the wizard's upload validation.

The introduction video therefore blocks submission until uploaded, and
`OnboardingWizard` collects it once, on the Documents step, alongside the
other requirements — the optional upload box that used to sit on the
Profile step was removed rather than leaving two inputs writing to one
collection. `documents()` supplies each row's upload property and help
text; the Blade view previously re-derived the collection→property map
itself and had no arm for `introduction_video`.

`required` remains a per-row column rather than a hardcoded `true` —
admins relax individual requirements from Document Requirements and the
seeder must not fight that.

`firstOrCreate`, never `updateOrCreate`: these rows are admin-editable,
so re-running the seeder must not revert a deliberate change (and the
model forbids hard deletion — retiring a requirement is `active = false`).

Note `InstructorResource` (account roster, ordinary `UserPolicy`) is
distinct from `InstructorOnboardingResource` (application review, gated
on `instructor.applications.review`) below.

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
