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
| `BookingSettings` | `booking` | Platform Foundation |
| `WalletSettings` | `wallet` | Platform Foundation |
| `MeetingSettings` | `meeting` | Platform Foundation |
| `InstructorSettings` | `instructor` | Platform Foundation |
| `ReferralSettings` | `referral` | Platform Foundation |
| `LocalizationSettings` | `localization` | Platform Foundation |
| `FeatureSettings` | `features` | Platform Foundation |

Compatibility note: the SRS-level "PaymentSettings" concept is implemented by the existing payment settings split (`PaymentGatewaySettings`, `PaymentConfigurationSettings`, `PaymentAdvancedSettings`, and `BankSettings`). The SRS-level "SecuritySettings" concept is implemented by the existing security settings split (`AuthenticationSettings`, `PasswordPolicySettings`, `LoginSecuritySettings`, `SessionSettings`, `RegistrationSettings`, and `AccountProtectionSettings`). Do not add duplicate aggregate settings classes unless a future architecture decision replaces the split model.

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

## Mail settings pattern

`MailSettings` stores the default sender plus category-specific transactional senders for Auth, Booking, Payment, Tutor, Wallet, Support, and Admin alerts. Production sender addresses must use a Resend-verified domain. See `resend.md` for DNS, SPF, DKIM, webhook, and production setup notes.

## Feature settings pattern

`FeatureSettings` stores coarse-grained Phase 1 feature flags only. Business logic is not wired to every flag yet. Future implementation should inject `FeatureSettings` or a dedicated service that wraps it, then keep controllers, Filament resources, and Livewire components thin.
