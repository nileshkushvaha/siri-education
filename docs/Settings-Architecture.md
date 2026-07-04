# Settings Architecture

## Overview

Application settings are stored in the `settings` table via **Spatie Laravel Settings 3.9.0** (`^3.9`). Each settings class is a typed PHP object where public properties map to DB-backed key-value pairs, grouped by a namespace string.

Settings are:
- **Strongly typed** — PHP property types are enforced
- **Cached** — reads are cached per-request by Spatie's repository
- **Admin-editable** — every settings class has a corresponding Filament page
- **Migration-versioned** — initial values are seeded via migration files in `database/settings/`

---

## All Settings Classes

| Class | Group | Admin Page | Description |
|---|---|---|---|
| `GeneralSettings` | `general` | General Settings | App name, org, contact, homepage, branding |
| `SeoSettings` | `seo` | SEO Settings | Meta defaults, robots, sitemap, OG |
| `MailSettings` | `mail` | Mail Settings | SMTP config, from address/name |
| `AuthenticationSettings` | `security_auth` | Authentication | Login method, remember me, verification, OAuth placeholders |
| `PasswordPolicySettings` | `security_password` | Password Policy | Min length, complexity, expiry, history |
| `LoginSecuritySettings` | `security_login` | Login Security | Max attempts, lockout duration, 2FA config |
| `SessionSettings` | `security_session` | Session | Driver, lifetime, single-session mode |
| `RegistrationSettings` | `security_registration` | Registration | Open/closed, allowed domains, default role |
| `AccountProtectionSettings` | `security_account` | Account Protection | Auto-lock, unlock, suspicious login alerts |
| `PaymentGatewaySettings` | `payment_gateways` | Payment Gateways | Per-gateway enable/keys for Stripe, Razorpay, PayPal, Cashfree, PayU, PhonePe, Manual |
| `PaymentConfigurationSettings` | `payment_configuration` | Payment Configuration | Currency, tax, invoice settings |
| `PaymentAdvancedSettings` | `payment_advanced` | Payment Advanced | Webhook retry, queue events flag |
| `BankSettings` | `payment_bank` | Bank Account | Bank details for manual payments |

---

## How to Read Settings

**In a service class (preferred — constructor injection):**

```php
class MyService
{
    public function __construct(
        private readonly PasswordPolicySettings $settings
    ) {}

    public function doSomething(): void
    {
        $minLength = $this->settings->min_length;
    }
}
```

**In a static context (enum method, notification, Filament form callback):**

```php
$duration = app(LoginSecuritySettings::class)->lockout_duration;
```

**Using `PasswordRuleBuilder` (password validation — always use this, never build inline):**

```php
use App\Services\Security\PasswordRuleBuilder;

'password' => [app(PasswordRuleBuilder::class)->build()],
```

---

## How to Write Settings

Settings are saved through their Filament page's `save()` method via `SecuritySettingsService` or the page's own save logic. Direct writes:

```php
$settings = app(MySettings::class);
$settings->some_field = 'new value';
$settings->save();

// Read back fresh from DB (flushes in-memory cache)
$fresh = app()->make(MySettings::class)->refresh();
```

---

## Settings Migrations

Settings seed data lives in `database/settings/`, not `database/migrations/`. This is a separate path managed by Spatie.

### Running them

```bash
php artisan migrate --path=database/settings
```

The `settings:migrate` artisan command **does not exist** in this project's version. Always use the path-based `migrate` command above.

### Creating a new settings migration

```bash
php artisan make:settings-migration fill_my_settings
```

This creates a file in `database/settings/`. The class structure:

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

The group name must match `YourSettingsClass::group()`.

---

## Adding a New Settings Group

1. **Create the settings class** in `app/Settings/`:

```php
declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MyFeatureSettings extends Settings
{
    public bool $feature_enabled;
    public int $feature_limit;

    public static function group(): string
    {
        return 'my_feature';
    }
}
```

2. **Create the seed migration** in `database/settings/`:

```php
public function up(): void
{
    $this->migrator->add('my_feature.feature_enabled', false);
    $this->migrator->add('my_feature.feature_limit', 10);
}
```

3. **Create the Filament page** in `app/Filament/Pages/Settings/` (copy `GeneralSettingsPage` as template, use `HasSettingsAccess` trait).

4. **Run the migration**: `php artisan migrate --path=database/settings`

5. **Add Gate abilities** in `AppServiceProvider` if the page needs permission-gating beyond super_admin.

---

## Security Settings Pattern

The 6 Security settings groups (`security_auth`, `security_password`, `security_login`, `security_session`, `security_registration`, `security_account`) follow a stricter pattern than general settings:

- Pages use `HasSecurityAccess` trait instead of `HasSettingsAccess`
- Each page has a `securityPermission()` method returning the `security.{page}.view` ability
- Every `save()` begins with `Gate::authorize('security.{page}.update')`
- All saves route through `SecuritySettingsService` which logs field-level diffs

When adding a new Security settings group, follow `AuthenticationPage` as the template.

---

## The `settings` Table

```
settings
├── id          (bigint)
├── group       (varchar)  — e.g. 'security_auth'
├── name        (varchar)  — e.g. 'login_enabled'
├── payload     (longtext) — JSON-encoded value
├── locked      (bool)     — prevents migration from overwriting
└── timestamps
```

All 13 settings groups store their values here. A row per property.
