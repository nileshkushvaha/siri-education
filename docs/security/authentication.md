# Authentication

## Flow

```
POST /login → LoginController → LoginService
           → checks: locked? blocked? inactive? password?
           → dispatches UserLoggedIn / LoginFailed event
           → LogLoginActivity listener writes LoginHistory + activity log
```

## Email verification — one-time code

Verification is code-based; there is no signed verification link anywhere
in the system.

```
POST /register → user created `pending_verification`, NOT signed in
              → SendRegistrationNotifications → EmailVerificationOtpService::issue()
              → session remembers the account (PendingEmailVerification)
              → redirect to GET /verify-email
GET  /verify-email → VerifyEmailController::show (no `auth` middleware)
              → VerifyEmailNotice Livewire: enter code / resend
              → EmailVerificationOtpService::verify()
              → AccountEmailVerificationService::verifyAndActivate()
              → Auth::login() + redirect via PortalResolver
```

An unverified account that tries to log in is handled the same way: a
`pending_verification` account is allowed past the status gate into the
credential check, and once the password is proven LoginService issues a
code, marks the session via `PendingEmailVerification` and the login
controller redirects to `/verify-email`. A code is never issued before
the password check, so typing someone else's address mails them nothing.

Codes are 6 digits, stored hashed on `email_verification_challenges`,
valid for `EmailVerificationOtpService::CODE_TTL_MINUTES`, capped at 5
wrong attempts per challenge plus a per-user/IP rate limit. Issuing a new
code invalidates the previous one.

Only two callers may set `PendingEmailVerification` (registration and a
successful password check) — that session marker is what allows the
automatic sign-in after a correct code.

## Key files

| File | Purpose |
|---|---|
| `app/Services/Auth/LoginService.php` | Core login logic, dispatches events |
| `app/Services/Auth/RegistrationService.php` | Registration |
| `app/Services/Auth/EmailVerificationOtpService.php` | Issues/checks the verification code |
| `app/Services/Auth/AccountEmailVerificationService.php` | Sole owner of the unverified → verified + activation transition |
| `app/Support/PendingEmailVerification.php` | Session marker naming the account the code screen may verify |
| `app/Services/Auth/PasswordResetService.php` | Token-based reset flow |
| `app/Actions/Auth/` | Single-purpose actions called by services |
| `app/Http/Controllers/Auth/` | Thin controllers, delegate to services |
| `app/Http/Requests/Auth/` | Form validation |
| `app/Listeners/Auth/LogLoginActivity.php` | Writes LoginHistory on every auth event |
| `app/Events/Auth/` | UserLoggedIn, UserLoggedOut, LoginFailed, UserRegistered, UserApproved |

## Google account activation (students & instructors)

**Not Google SSO.** Google is used once, to prove that a person controls the
email address of an account an administrator (or self-registration) has
already created. After that the account uses email + password only.

```
GET /auth/google → Socialite (openid email profile, stateful) → Google
GET /auth/google/callback → GoogleActivationController → GoogleActivationService
    1. AuthenticationSettings::social_login_enabled + GOOGLE_CLIENT_* present
    2. Google email present AND email_verified
    3. users.google_subject match, else users.email match — none → register
       as a STUDENT (RegistrationService::registerVerifiedExternal) when
       RegistrationSettings::self_registration_enabled; inactive + "awaiting
       approval" when require_admin_approval; refused when self-registration
       is off. Never an instructor or admin role.
    4. user already linked to a DIFFERENT Google subject → deny
    5. PortalResolver::usesAdminPortal() → deny (super_admin/manager use /admin/login)
    6. already linked AND not awaiting activation password → "already activated"
    7. pending_verification + same email → AccountEmailVerificationService::verifyAndActivate()
    8. first link: google_subject / google_email / google_linked_at,
       must_change_password = true when password_changed_at is null
    9. LoginService::completeVerifiedLogin(…, 'google') — same gates and
       side effects as a password login (lock, alerts, login_histories)
→ dashboard stack → password.change.required → "Create your password"
→ ForcePasswordChangeController → password_changed_at set → activated
→ (students) Book → student.profile.complete → /account/complete-profile
   country, mobile number (+prefix), terms → booking wizard
```

### Google sign-up (students only)

Google's sign-in scopes return **no phone number and no country** — only
name, email, picture and sometimes a `locale` hint (`en-IN`). A student
created from Google therefore has just a name and a verified email, plus a
random unusable password and `must_change_password`. Everything else is
collected on **Complete your profile**, enforced as the booking
precondition:

- `App\Services\Student\StudentProfileCompletenessService` — the single
  rule set: a name, an active country, a mobile number (`phone_e164`),
  accepted terms + privacy. Applies to every student; form-registered
  students already satisfy it. (Distinct from `ProfileCompletionService`,
  which scores optional richness as a percentage.)
- `EnsureStudentProfileComplete` (alias `student.profile.complete`) on the
  `booking.create` route, and `WizardBookingService::book()/bookRecurring()`
  server-side (Livewire bypasses route middleware) → `BookingException`.
- `CompleteProfileController` + `CompleteProfileRequest` (`/account/complete-profile`):
  country from the supported-billing list (`SupportedRegistrationCountry`),
  phone normalised by `PhoneNumberService` via `UpdateProfileAction`, timezone
  seeded from the country only when the profile has none (TZ-1), terms fields
  written exactly like `RegisterUserAction`. The Google `locale` region only
  **preselects** the country. Audit: `profile_completed` (`users` log).
- Dashboard shows an amber "Complete your profile to start booking" card
  while anything is missing.
- Audit for the sign-up itself: `google_student_registered` (`auth` log), plus
  the normal `UserRegistered` event (welcome / pending-approval email, admin bell).

Rules (non-negotiable):

- Google never creates users, roles or permissions, and never reactivates an
  `inactive`, `blocked` or `suspended` account. The local row decides.
- `users.google_subject` (Google `sub`) is the permanent link and is unique.
  Email only matters for the first match; a Google subject is never moved to
  another user automatically.
- Activation-only: once the password exists, "Continue with Google" is refused
  with "Your account has already been activated…". A user who linked but never
  set the password may return through Google.
- An existing user who already set their own password is linked and signed in
  without a forced reset.
- `PasswordLifecycleService::awaitingActivationPassword()` forces the
  set-password step regardless of `PasswordPolicySettings::force_change_on_first_login`.
- Every denial explains what went wrong with the visitor's OWN Google account and
  what to do next (`GoogleActivationResult::message()`), e.g. "No student or
  instructor account is registered for x@y.com". Google has already proven they
  own that address, so this reveals nothing new to them. The other user's state is
  never described (IdentityConflict stays vague). Full reason in the audit trail.
- No Google access/refresh token is stored anywhere.

Configuration: env `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
`GOOGLE_REDIRECT_URI` (`config/services.php` → `google`), an OAuth 2.0 **Web
application** client in Google Cloud whose authorized redirect URI matches
`/auth/google/callback` exactly per environment (local, staging, production).
This client is separate from the Meet/Drive service account in `MeetingSettings`.
Toggle: Admin → Security → Authentication → "Google Account Activation"
(`AuthenticationSettings::social_login_enabled`).

Audit events (`auth` log): `google_account_linked`, `google_student_registered`,
`google_login_rejected` (properties: `reason`, `login_result`), `account_activated`, plus the usual
`login` entry and a `login_histories` row with `login_method = google`.

Key files: `app/Services/Auth/GoogleActivationService.php`,
`app/Http/Controllers/Auth/GoogleActivationController.php`,
`app/Enums/GoogleActivationResult.php`, `app/Services/Auth/RegistrationService.php`
(`registerVerifiedExternal`), `app/Services/Student/StudentProfileCompletenessService.php`,
`app/Http/Middleware/EnsureStudentProfileComplete.php`,
`app/Http/Controllers/Account/CompleteProfileController.php`,
`database/migrations/2026_09_05_100000_add_google_identity_to_users_table.php`,
`tests/Feature/Auth/GoogleActivationTest.php`, `tests/Feature/Auth/GoogleStudentRegistrationTest.php`,
`tests/Feature/Account/CompleteProfileTest.php`.

## Account states

`User::$status` values: `active`, `pending_verification`, `inactive`, `blocked`, `suspended`

A user is locked (too many failed attempts) via `User::$locked_until`. This is separate from `$status`.

## Settings that control auth behaviour

- `LoginSecuritySettings` — max attempts, lockout duration
- `AuthenticationSettings` — login method, remember me, email verification, registration toggle
- `RegistrationSettings` — open/closed, allowed domains, default role, approval required
- `AccountProtectionSettings` — auto-lock threshold, suspicious login alerts

## Email notifications triggered by auth events

- `SendRegistrationNotifications` — verification code, welcome email, admin notification on new registration
- `SendWelcomeNotification` — fires after email verification (`Verified` event)
- `SendApprovalNotification` — fires when admin changes status to `active`

## Login History

Every auth attempt is recorded in `login_histories` table via `LogLoginActivity`.

Fields: `user_id`, `status`, `ip_address`, `user_agent`, `browser`, `platform`, `device_type`, `session_id`, `login_method`, `logged_in_at`, `logged_out_at`.

Viewable in admin: System → Login History.
