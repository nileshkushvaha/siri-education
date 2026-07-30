# Authentication

## Flow

```
POST /login → LoginController → LoginService
           → checks: locked? blocked? inactive? password?
           → dispatches UserLoggedIn / LoginFailed event
           → LogLoginActivity listener writes LoginHistory + activity log
```

## Key files

| File | Purpose |
|---|---|
| `app/Services/Auth/LoginService.php` | Core login logic, dispatches events |
| `app/Services/Auth/RegistrationService.php` | Registration, email verification |
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

- `SendRegistrationNotifications` — welcome email, admin notification on new registration
- `SendWelcomeNotification` — fires after email verification (`Verified` event)
- `SendApprovalNotification` — fires when admin changes status to `active`

## Login History

Every auth attempt is recorded in `login_histories` table via `LogLoginActivity`.

Fields: `user_id`, `status`, `ip_address`, `user_agent`, `browser`, `platform`, `device_type`, `session_id`, `login_method`, `logged_in_at`, `logged_out_at`.

Viewable in admin: System → Login History.
