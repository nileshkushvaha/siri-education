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
