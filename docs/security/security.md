# Security

## Admin pages (Security group)

| Page | Route | Gate abilities |
|---|---|---|
| Authentication | `/admin/security/authentication` | `security.authentication.view/update` |
| Password Policy | `/admin/security/password-policy` | `security.password_policy.view/update` |
| Login Security | `/admin/security/login-security` | `security.login_security.view/update` |
| Session | `/admin/security/session` | `security.session.view/update` |
| Registration | `/admin/security/registration` | `security.registration.view/update` |
| Account Protection | `/admin/security/account-protection` | `security.account_protection.view/update` |

All pages use the `HasSecurityAccess` trait, which reads `securityPermission()` on the page class and enforces view/update separation. Every `save()` begins with `Gate::authorize('security.{page}.update')`.

## Key services

`SecuritySettingsService` — all Security page saves route through here. Logs field-level diffs (excluding sensitive fields: password, secret, key, token) to the audit trail via `activity('security')`.

`AdminSessionService` — force-logout-all, session invalidation.

`PasswordRuleBuilder` — single source of truth for password validation rules built from `PasswordPolicySettings`. Always use this, never build `Password::min()` chains inline.

## Settings classes

| Class | Group |
|---|---|
| `AuthenticationSettings` | `security_auth` |
| `PasswordPolicySettings` | `security_password` |
| `LoginSecuritySettings` | `security_login` |
| `SessionSettings` | `security_session` |
| `RegistrationSettings` | `security_registration` |
| `AccountProtectionSettings` | `security_account` |

## Policy

All Security Gate abilities are defined in `app/Policies/Security/SecurityPolicy.php` and registered in `AppServiceProvider`.

## Activity log events

| Event | Log name | Description |
|---|---|---|
| `settings_updated` | `security` | Any security settings change, with `page` and `changes` in properties |
| `force_logout_all` | `security` | Admin forced logout of all sessions |

These events trigger admin bell notifications via the Activity Log pipeline.
