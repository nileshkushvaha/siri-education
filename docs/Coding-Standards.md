# Coding Standards

## PHP

- `declare(strict_types=1)` on all new files. (109 existing files predate this rule — add when touching those files.)
- Final classes for Services, Actions, Notifications, and Requests. Avoid final on models.
- Constructor property promotion for readonly dependencies.
- Named arguments where method signatures are long or ambiguous.
- No inline `app(Foo::class)` except in static contexts (enum methods, static helpers). Prefer constructor injection everywhere else.

## Filament

### Resource structure

Every resource follows the same sub-directory pattern:

```
app/Filament/Resources/{Name}/
├── {Name}Resource.php          — resource class (navigation, slug, model binding)
├── Pages/
│   ├── List{Name}s.php
│   ├── Create{Name}.php
│   └── Edit{Name}.php
├── Schemas/
│   └── {Name}Form.php          — static form() method returning Schema
└── Tables/
    └── {Name}sTable.php        — static table() method returning Table
```

Schemas and Tables are static classes returning their respective objects — they are not instantiated. The Resource class delegates to them:

```php
public static function form(Form $form): Form
{
    return UserForm::configure($form);
}
```

### Filament v5 form API

Use `content(Schema $schema)` on pages, not `form(Form $form)`. The `Schema` class replaces `Form` for non-resource pages. See any Security page for the current pattern.

### Conditional field visibility

Fields with `.visible(fn($get) => ...)` are **excluded from `getState()` when hidden**. In tests, always set the controlling toggle before setting the dependent field:

```php
Livewire::test(PasswordPolicyPage::class)
    ->set('data.expiry_enabled', true)   // must come first
    ->set('data.expiry_days', 60)
    ->call('save');
```

## Gate and Authorization

### Super admin

`Gate::before()` in `AppServiceProvider` grants `super_admin` unconditional access to every Gate ability. This runs before any policy check. Do not replicate this logic in individual policies — it's a framework-level bypass.

### Direct permission checks

In policies and traits, use `$user->hasPermissionTo()` directly, **never** `$user->can()`. The `can()` method re-enters the Gate, which causes circular resolution when called from inside a Gate callback.

```php
// Correct
return $user->hasPermissionTo('security.authentication.view');

// Wrong — circular Gate resolution
return $user->can('security.authentication.view');
```

### PermissionDoesNotExist

Always catch `Spatie\Permission\Exceptions\PermissionDoesNotExist` when calling `hasPermissionTo()` with a permission name that may not exist (e.g., during tests where only a subset of permissions are seeded):

```php
try {
    return $user->hasPermissionTo(static::securityPermission());
} catch (PermissionDoesNotExist) {
    return false;
}
```

### Gate::authorize() in save()

Every Settings/Security page `save()` method must begin with `Gate::authorize()` as a second layer of protection (the first layer is `canAccess()` blocking page load):

```php
public function save(): void
{
    Gate::authorize('security.authentication.update');
    try {
        $data = $this->form->getState();
    } catch (Halt $exception) {
        return;
    }
    // ...
}
```

## Settings

Use `app(XxxSettings::class)` for container resolution in static contexts (enum methods, notifications). Use constructor injection everywhere else.

Never resolve settings in class constructors that are instantiated on every request — settings are DB-backed and cached by Spatie's repository, but resolve lazily.

The `PasswordRuleBuilder` (`app/Services/Security/PasswordRuleBuilder.php`) is the single source of truth for password validation rules. Always use it instead of building `Password::min()` chains inline:

```php
// Correct
'password' => [app(PasswordRuleBuilder::class)->build()],

// Wrong — ignores admin-configured policy
'password' => [Password::min(8)->mixedCase()->numbers()],
```

## Activity Logging

The `SecuritySettingsService` logs field-level diffs for all Security settings changes. The pattern:

1. Capture `$old = $settings->toArray()` before modifying
2. Compute `$changes = collect($new)->diff($old)` style diff
3. Exclude `SENSITIVE_FIELDS` (password, secret, key, token)
4. Log via `activity('security')->withProperties(['changes' => $changes])->log(...)`

New settings pages that allow admin changes should follow this same pattern.

## Naming Conventions

| Type | Convention | Example |
|---|---|---|
| Settings class | `{Domain}Settings` | `LoginSecuritySettings` |
| Service | `{Domain}Service` | `SecuritySettingsService` |
| Action | `{Verb}{Noun}Action` | `RegisterUserAction` |
| Policy | `{Model}Policy` | `ContentBlockPolicy` |
| Gate ability | `{domain}.{resource}.{action}` | `security.authentication.update` |
| Settings group | `{domain}_{sub}` | `security_auth`, `payment_bank` |
| Permission | `{action}_{resource}` (Shield-generated) | `view_post`, `create_page` |

## File Placement Rules

- Security page policy methods → `app/Policies/Security/SecurityPolicy.php`
- Security Filament pages → `app/Filament/Pages/Security/`
- Security services → `app/Services/Security/`
- CMS models → `app/Content/Models/` (namespace `App\Content\Models`)
- All other models → `app/Models/` (namespace `App\Models`)
- Do not create policies in `app/Content/Policies/` — that directory was deleted as dead code. All active policies live in `app/Policies/`.

## Tests

- Test classes mirror the feature path: `tests/Feature/Security/` for Security features.
- Every test class that touches the DB uses `RefreshDatabase`.
- Settings tests must call `$this->artisan('migrate', ['--path' => 'database/settings'])` in `setUp()` — the `settings:migrate` command does not exist.
- Use `$settings->refresh()` after a save to re-read from DB (avoids stale in-memory state).
- Always `actingAs($this->superAdmin)` before Livewire Filament tests.

## Code Style

Laravel Pint enforces style. Run before committing:

```bash
./vendor/bin/pint
```

The project uses the default Pint ruleset (Laravel preset). No custom rules beyond framework defaults.
