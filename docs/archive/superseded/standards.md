# Standards

## PHP

- `declare(strict_types=1)` on all files
- `final` on Services, Actions, Notifications, Requests — not on Models
- Constructor property promotion for readonly dependencies
- Named arguments when method signatures are long or ambiguous
- No inline `app(Foo::class)` except in static contexts (enum methods, static helpers) — prefer constructor injection

## Filament Resource structure

```
app/Filament/Resources/{Name}/
├── {Name}Resource.php
├── Pages/
│   ├── List{Name}s.php
│   ├── Create{Name}.php
│   └── Edit{Name}.php
├── Schemas/
│   └── {Name}Form.php       ← static configure(Schema) method
└── Tables/
    └── {Name}Table.php      ← static configure(Table) method
```

Resources delegate to static schema/table classes:

```php
public static function form(Form $form): Form
{
    return UserForm::configure($form);
}
```

## Authorization

`Gate::before()` in `AppServiceProvider` grants `super_admin` unconditional access. Never replicate in individual policies.

Always use `$user->hasPermissionTo()` directly in policies — never `$user->can()` (causes circular Gate resolution).

Catch `PermissionDoesNotExist` when checking permissions that may not exist in all environments:

```php
try {
    return $user->hasPermissionTo('some.permission');
} catch (PermissionDoesNotExist) {
    return false;
}
```

Every Settings/Security page `save()` must begin with `Gate::authorize()`.

## Password validation

Always use `PasswordRuleBuilder`. Never build `Password::min()` chains inline:

```php
'password' => [app(PasswordRuleBuilder::class)->build()],
```

## Naming conventions

| Type | Convention | Example |
|---|---|---|
| Settings class | `{Domain}Settings` | `LoginSecuritySettings` |
| Service | `{Domain}Service` | `SecuritySettingsService` |
| Action | `{Verb}{Noun}Action` | `RegisterUserAction` |
| Policy | `{Model}Policy` | `ContentBlockPolicy` |
| Gate ability | `{domain}.{resource}.{action}` | `security.authentication.update` |
| Settings group | `{domain}_{sub}` | `security_auth` |

## File placement

- Security pages → `app/Filament/Pages/Security/`
- Security services → `app/Services/Security/`
- Security policy methods → `app/Policies/Security/SecurityPolicy.php`
- CMS models → `app/Content/Models/` (namespace `App\Content\Models`)
- All other models → `app/Models/`
- All active policies → `app/Policies/` (not `app/Content/Policies/`)

## Code style

Run Pint before committing: `./vendor/bin/pint`
