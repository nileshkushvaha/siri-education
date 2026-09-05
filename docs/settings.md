# Settings

## Overview

Application settings are stored in the `settings` table via **Spatie Laravel Settings** (`^3.9`). Each settings class is a typed PHP object whose public properties map to DB-backed key/value pairs, grouped by a namespace string returned from `group()`. Reads are cached per-request by Spatie's repository.

```
settings
├── id          (bigint)
├── group       (varchar)  — e.g. 'security_auth'
├── name        (varchar)  — e.g. 'login_enabled'
├── payload     (longtext) — JSON-encoded value
├── locked      (bool)     — prevents a migration from overwriting a changed value
└── timestamps
```

Settings classes live in `app/Settings/`; initial/default values are seeded via migration files in `database/settings/` (a separate path from `database/migrations/`, managed by Spatie).

## Current settings classes

This list is generated from `app/Settings/*.php` — if you add or remove a settings class, update this table (or run the command below to check it's still accurate):

```bash
ls app/Settings/*.php | xargs -n1 basename -s .php
```

| Class | Group | Admin page |
|---|---|---|
| `GeneralSettings` | `general` | General Settings |
| `SeoSettings` | `seo` | SEO Settings (defaults, analytics, Open Graph, plus per-page overrides for home, blog, instructors, FAQs, login, register, become-instructor, forgot-password) |
| `MailSettings` | `mail` | Mail Settings |
| `AuthenticationSettings` | `security_auth` | Authentication |
| `PasswordPolicySettings` | `security_password` | Password Policy |
| `LoginSecuritySettings` | `security_login` | Login Security |
| `SessionSettings` | `security_session` | Session |
| `RegistrationSettings` | `security_registration` | Registration |
| `AccountProtectionSettings` | `security_account` | Account Protection |
| `PaymentGatewaySettings` | `payment_gateways` | Payment Settings (Gateways tab) |
| `PaymentConfigurationSettings` | `payment_configuration` | Payment Settings (Configuration tab) |
| `PaymentAdvancedSettings` | `payment_advanced` | Payment Settings (Advanced tab) |
| `BankSettings` | `payment_bank` | Payment Settings (Bank Account tab) |
| `BookingSettings` | `booking` | Platform Foundation |
| `MeetingSettings` | `meeting` | Meeting Settings |
| `InstructorSettings` | `instructor` | Platform Foundation |
| `LocalizationSettings` | `localization` | Platform Foundation |
| `FeatureSettings` | `features` | Platform Foundation |
| `HomeworkSettings` | `homework` | Homework Reminder Settings |
| `ReviewSettings` | `reviews` | Review & Quality Settings |
| `InstructorEarningSettings` | `instructor_earnings` | Instructor Earning Settings |
| `RazorpayXPayoutSettings` | `razorpayx_payout` | RazorpayX Payout Settings |
| `AiSettings` | `ai` | AI Platform Settings |
| `DemoConversionIncentiveSettings` | `demo_conversion_incentive` | Demo Conversion Incentive Settings |
| `ComplianceMonitoringSettings` | `compliance_monitoring` | *(no admin page yet — configured via settings migration defaults only)* |
| `InvoiceSettings` | `invoice` | *(no admin page yet)* |
| `LessonSettings` | `lessons` | *(no admin page yet)* |
| `MessagingSettings` | `messaging` | *(no admin page yet)* |
| `SupportCaseSettings` | `support_case` | *(no admin page yet)* |

Compatibility note: the SRS-level "PaymentSettings" concept is implemented by the payment settings split above (`PaymentGatewaySettings`/`PaymentConfigurationSettings`/`PaymentAdvancedSettings`/`BankSettings`); the SRS-level "SecuritySettings" concept is implemented by the security settings split (`AuthenticationSettings`/`PasswordPolicySettings`/`LoginSecuritySettings`/`SessionSettings`/`RegistrationSettings`/`AccountProtectionSettings`). Do not add a duplicate aggregate settings class unless a future architecture decision replaces the split model.

## Reading settings

Constructor injection (preferred, in any class the container resolves):

```php
public function __construct(private readonly LoginSecuritySettings $settings) {}
```

Static context (enum method, notification, Filament closure):

```php
$value = app(LoginSecuritySettings::class)->some_field;
```

## Writing settings

Settings are normally saved through their Filament page's `save()` method (routed through `SecuritySettingsService` for the 6 Security groups, or the page's own save logic otherwise). Direct writes look like:

```php
$settings->some_field = 'value';
$settings->save();

// Read back fresh from the DB (flushes in-memory cache)
$fresh = app()->make(MySettings::class)->refresh();
```

## Settings migrations

Seed files live in `database/settings/`, **not** `database/migrations/`.

```bash
# Run settings migrations
php artisan migrate --path=database/settings

# Create a new settings migration
php artisan make:settings-migration fill_my_settings
```

`settings:migrate` does **not** exist as an artisan command in this project — always use the path-based `migrate` command above.

A settings migration looks like:

```php
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('group_name.field_name', 'default_value');
        $this->migrator->add('group_name.another_field', true);
    }
};
```

The group name must match the settings class's own `group()` return value.

## Adding a new settings group

1. Create `app/Settings/MySettings.php`:

   ```php
   declare(strict_types=1);

   namespace App\Settings;

   use Spatie\LaravelSettings\Settings;

   class MySettings extends Settings
   {
       public bool $feature_enabled;
       public int $feature_limit;

       public static function group(): string
       {
           return 'my_feature';
       }
   }
   ```

2. Create the seed migration in `database/settings/`:

   ```php
   public function up(): void
   {
       $this->migrator->add('my_feature.feature_enabled', false);
       $this->migrator->add('my_feature.feature_limit', 10);
   }
   ```

3. Create the Filament page in `app/Filament/Pages/Settings/` (copy `GeneralSettingsPage` as a template, use the `HasSettingsAccess` trait).
4. Run `php artisan migrate --path=database/settings`.
5. Register Gate abilities in `AppServiceProvider` if the page needs permission-gating beyond `super_admin`.

## Security settings pattern

The 6 Security settings groups (`security_auth`, `security_password`, `security_login`, `security_session`, `security_registration`, `security_account`) follow a stricter pattern than general settings:

- Pages use the `HasSecurityAccess` trait instead of `HasSettingsAccess`.
- Each page has a `securityPermission()` method returning the `security.{page}.view` ability.
- Every `save()` begins with `Gate::authorize('security.{page}.update')`.
- All saves route through `SecuritySettingsService`, which logs field-level diffs (excluding password/secret/key/token fields) via the Activity Log pipeline.

When adding a new Security settings group, follow `AuthenticationPage` as the template.

## Mail settings pattern

`MailSettings` stores the default sender plus category-specific transactional senders (Auth, Booking, Payment, Instructor, Wallet, Support, Admin alerts). Production sender addresses must use a Resend-verified domain — see `resend.md` for DNS/SPF/DKIM/webhook setup.

## Feature settings pattern

`FeatureSettings` gates coarse-grained module on/off switches (e.g. `wallet_enabled`, `referral_enabled` — see `docs/architecture/platform-settings-feature-flags.md` for exactly which switches exist and why some related settings intentionally have no separate `enabled` flag of their own). Not every flag is wired to every piece of business logic yet — when adding a feature behind a flag, inject `FeatureSettings` (or a dedicated service wrapping it) and keep controllers/resources/Livewire components thin.
