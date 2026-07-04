# Settings

## Overview

All settings use **Spatie Laravel Settings 3.9.0** (`^3.9`). Values live in the `settings` table (group + name → JSON payload). Reads are cached per-request. Every settings class has a corresponding Filament admin page.

## All settings classes

| Class | Group | Admin page |
|---|---|---|
| `GeneralSettings` | `general` | General Settings |
| `SeoSettings` | `seo` | SEO Settings |
| `MailSettings` | `mail` | Mail Settings |
| `AuthenticationSettings` | `security_auth` | Authentication |
| `PasswordPolicySettings` | `security_password` | Password Policy |
| `LoginSecuritySettings` | `security_login` | Login Security |
| `SessionSettings` | `security_session` | Session |
| `RegistrationSettings` | `security_registration` | Registration |
| `AccountProtectionSettings` | `security_account` | Account Protection |
| `PaymentGatewaySettings` | `payment_gateways` | Payment Gateways |
| `PaymentConfigurationSettings` | `payment_configuration` | Payment Configuration |
| `PaymentAdvancedSettings` | `payment_advanced` | Payment Advanced |
| `BankSettings` | `payment_bank` | Bank Account |

## Reading settings

Constructor injection (preferred):

```php
public function __construct(private readonly LoginSecuritySettings $settings) {}
```

Static context (enum, notification, Filament callback):

```php
$value = app(LoginSecuritySettings::class)->some_field;
```

## Writing settings

```php
$settings->some_field = 'value';
$settings->save();

// Read fresh from DB after save
$fresh = app()->make(MySettings::class)->refresh();
```

## Settings migrations

Seed files live in `database/settings/`, NOT `database/migrations/`.

```bash
# Run settings migrations
php artisan migrate --path=database/settings

# Create a new settings migration
php artisan make:settings-migration fill_my_settings
```

`settings:migrate` does NOT exist — always use the path-based `migrate` command.

## Adding a new settings group

1. Create `app/Settings/MySettings.php` with `public static function group(): string`
2. Create seed migration in `database/settings/`
3. Create Filament page in `app/Filament/Pages/Settings/` (use `GeneralSettingsPage` as template)
4. Run `php artisan migrate --path=database/settings`
5. Register Gate abilities in `AppServiceProvider` if permission-gated

## Security settings pattern

The 6 security settings groups follow a stricter pattern — see `security.md`.
All saves route through `SecuritySettingsService` which logs field-level diffs.
