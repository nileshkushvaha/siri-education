# Identity and Access Foundation

## Executive Summary

The identity foundation remains a single Laravel authentication system backed by the existing `users` table and `App\Models\User`. Students, instructors, managers, and administrators are represented by one base identity and differentiated through Spatie Permission roles/permissions plus profile metadata.

This Phase 1 pass did not create a new auth system, duplicate users table, student table, instructor table, or parallel role model. It reused the existing auth services, actions, middleware, policies, Filament Shield configuration, portal resolver, and profile linkage.

Implemented foundation enhancements:

- Added nullable terms/privacy acceptance metadata to `users`.
- Persisted registration consent through `RegistrationService` and `RegisterUserAction`.
- Ensured the Livewire registration flow passes consent to the existing registration service.
- Standardized public instructor visibility so only active users with approved or active instructor profiles appear publicly.
- Added regression tests for registration consent and instructor access restrictions.

## Source of Truth

| Concern | Existing source |
|---|---|
| Base identity | `app/Models/User.php`, `database/migrations/0001_01_01_000000_create_users_table.php` |
| Profile linkage | `app/Models/UserProfile.php`, `app/Observers/UserObserver.php`, `database/migrations/2026_06_26_193822_create_user_profiles_table.php` |
| Roles and permissions | Spatie Permission models, `database/seeders/ShieldSeeder.php`, `database/seeders/DefaultRolesAndUsersSeeder.php` |
| Filament Shield | `config/filament-shield.php`, `app/Policies/*`, `app/Providers/AppServiceProvider.php` |
| Portal routing | `app/Services/PortalResolver.php` |
| Login | `app/Services/Auth/LoginService.php`, `app/Actions/Auth/AttemptLoginAction.php`, `app/Http/Controllers/Auth/LoginController.php`, `app/Livewire/Frontend/Auth/LoginForm.php` |
| Registration | `app/Services/Auth/RegistrationService.php`, `app/Actions/Auth/RegisterUserAction.php`, `app/Http/Requests/Auth/RegisterRequest.php`, `app/Livewire/Frontend/Auth/RegisterForm.php` |
| Email verification | `App\Models\User` implements `MustVerifyEmail`, `app/Http/Middleware/EnsureEmailVerifiedIfRequired.php`, `app/Settings/AuthenticationSettings.php` |
| Account status | `User::STATUS_PENDING`, `active`, `inactive`, `blocked`, `suspended`; `app/Http/Middleware/EnsureAccountIsActive.php` |
| Instructor status | `app/Enums/InstructorStatus.php`, `user_profiles.instructor_status`, `app/Services/Instructor/InstructorService.php`, `app/Policies/InstructorPolicy.php` |
| Activity logging | `Spatie\Activitylog` on `User` and `UserProfile`, auth events/listeners, login history models |

## Audit Findings

### Users and Base Identity

`users` already contains the required base identity fields: name, first/last name, email, verified timestamp, password, account status, lock state, login alert preferences, last login metadata, and password lifecycle metadata.

`App\Models\User` already:

- Implements `MustVerifyEmail`.
- Uses Spatie Permission `HasRoles`.
- Uses Spatie Activitylog.
- Owns relationships for profile, login history, instructor subjects, availability, experiences, education, and hosted bookings.
- Delegates Filament panel access decisions to `PortalResolver`.
- Keeps `isSuperAdmin()` as the only role helper, matching the portal architecture rule.

### Roles, Permissions, and Shield

Authorization is built on Spatie Permission and Filament Shield. Policies remain the first access-control layer for resources. Admin access is permission-controlled through policies and Shield-generated permissions; portal routing is separate and owned by `PortalResolver`.

No duplicate role or permission abstraction was added.

### Registration and Terms Acceptance

Before this pass, `RegisterRequest` and `RegisterForm` required a terms checkbox, but acceptance was not stored on the base identity. The following nullable fields now exist on `users`:

- `terms_accepted_at`
- `privacy_accepted_at`
- `terms_version`
- `privacy_version`
- `terms_accepted_ip`
- `privacy_accepted_ip`
- `terms_accepted_user_agent`
- `privacy_accepted_user_agent`

The classic controller path and Livewire path both delegate to `RegistrationService`, which enriches registration data with request IP and user agent before calling `RegisterUserAction`.

### Email Verification

Email verification is already supported and enforced conditionally through `AuthenticationSettings::email_verification_required` and `EnsureEmailVerifiedIfRequired`. Existing tests cover dashboard access for verified and unverified users.

No new verification system was created.

### Account Status

Account status is already standardized on `users.status` with constants in `App\Models\User`. `EnsureAccountIsActive` blocks locked, blocked, inactive, and suspended accounts from protected frontend areas. `LoginService` also checks lock and status before completing login.

No status column was recreated or renamed.

### Instructor Access

Instructor identity remains role-based on the existing `users` table with profile metadata in `user_profiles`.

Public instructor access now requires:

- `users.status = active`
- `user_profiles.profile_visibility = public`
- role `instructor`
- `user_profiles.instructor_status` is `approved` or `active` (renamed from `published` — see `docs/architecture/user-lifecycle-foundation.md`)

Owners and users with `Update:User` may still view private or non-public instructor profiles for management purposes.

### Activity and Login Events

Login and registration already use domain services/events/listeners:

- `LoginService` records successful login metadata and dispatches `UserLoggedIn`.
- `RegistrationService` dispatches `UserRegistered`.
- `User` and `UserProfile` use Activitylog.
- Login history and session tracking already exist.

This pass did not move notification or audit responsibilities into controllers or Filament resources.

## Files Reused

- `app/Models/User.php`
- `app/Models/UserProfile.php`
- `app/Enums/InstructorStatus.php`
- `app/Services/Auth/RegistrationService.php`
- `app/Actions/Auth/RegisterUserAction.php`
- `app/Livewire/Frontend/Auth/RegisterForm.php`
- `app/Services/Instructor/InstructorService.php`
- `app/Policies/InstructorPolicy.php`
- `app/Http/Middleware/EnsureAccountIsActive.php`
- `app/Http/Middleware/EnsureEmailVerifiedIfRequired.php`
- `app/Services/PortalResolver.php`
- `tests/Feature/Auth/RegisterFormTest.php`
- `tests/Feature/Instructor/InstructorDetailTest.php`
- `tests/Feature/Instructor/InstructorListingTest.php`
- `tests/Feature/Instructor/InstructorServiceTest.php`

## New Files

- `database/migrations/2026_07_05_120000_add_terms_privacy_acceptance_to_users_table.php`
- `docs/architecture/identity-access-foundation.md`

## Migration Summary

One additive migration was created. It only adds nullable consent metadata columns to the existing `users` table and drops those columns on rollback.

No production-risk renames were performed.

No duplicate auth, student, instructor, role, permission, or profile tables were created.

## Tests

Updated coverage:

- `tests/Feature/Auth/RegisterFormTest.php`
  - Verifies Livewire registration records terms and privacy acceptance timestamps.
- `tests/Feature/Instructor/InstructorDetailTest.php`
  - Verifies pending public instructors are forbidden publicly.
  - Verifies inactive approved instructors are forbidden publicly.
- `tests/Feature/Instructor/InstructorListingTest.php`
  - Verifies pending instructors are excluded from listing.
- `tests/Feature/Instructor/InstructorServiceTest.php`
  - Verifies pending instructors are excluded by the service.

Existing coverage retained:

- Email verification dashboard redirects in `tests/Feature/Security/AuthenticationSettingsTest.php`.
- Account status and auth settings tests under `tests/Feature/Auth` and `tests/Feature/Security`.

## Risks and Follow-Ups

| Risk | Recommendation |
|---|---|
| `RegisterUserAction` creates a `UserProfile` while `UserObserver` also creates one on user creation. | Audit profile duplication separately before changing behavior. Do not refactor this inside identity/access hardening unless tests prove duplicate profiles are impossible or harmless. |
| Some existing auth/security code calls `activity()` directly. | Gradually align with the documented `AuditTrailService` rule in a dedicated audit pass. |
| Some authorization checks use `$user->can('Update:User')`, which may throw if a permission is missing in certain contexts. | Prefer local permission helper methods using `hasPermissionTo()` with `PermissionDoesNotExist` handling when touching those files. |
| Terms/privacy versions are nullable because no settings-backed legal version source exists yet. | Add settings-backed legal document versioning in a future compliance phase if needed. |

## Confirmation

This implementation preserved the existing authentication architecture:

- One base identity table: `users`.
- One user model: `App\Models\User`.
- Authorization through Spatie Permission and Filament Shield.
- Portal routing through `PortalResolver`.
- Business rules through services/actions/policies.
- No duplicate authentication system was created.
