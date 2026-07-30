# Testing

## Running tests

```bash
composer test                    # always use this
php artisan test --env=testing   # equivalent
```

Never use `php artisan test` without `--env=testing` — it would hit the dev database.

## Databases

- Dev: `enterprise_app`
- Test: `enterprise_app_testing`

The safety guard in `tests/TestCase.php` checks `app()->environment('testing')` AND that the database name is not `enterprise_app` before allowing `RefreshDatabase`. Never remove this guard.

## Test structure

```
tests/
├── TestCase.php           ← base class with safety guard
├── Feature/
│   ├── Security/          ← security module tests
│   ├── Notifications/     ← notification pipeline tests
│   ├── AuditTrail/        ← audit trail tests
│   ├── Navigation/        ← navigation integrity tests
│   └── ...                ← other feature tests
└── Unit/
```

Test classes mirror feature paths: `tests/Feature/Security/` for Security features.

## Setup rules

- Every test class touching the DB: `use RefreshDatabase`
- Settings tests: call `$this->artisan('migrate', ['--path' => 'database/settings'])` in `setUp()`
- Filament Livewire tests: `$this->actingAs($superAdmin)` before `Livewire::test()`
- After a `save()` in settings tests: use `$settings->refresh()` to read fresh from DB

## Filament conditional fields

Fields with `.visible(fn($get) => ...)` are excluded from `getState()` when hidden. Always set the controlling toggle before the dependent field:

```php
Livewire::test(SomePage::class)
    ->set('data.toggle_field', true)   // must come first
    ->set('data.dependent_field', 60)
    ->call('save');
```

## Pint

Run before committing, after implementation:

```bash
./vendor/bin/pint
composer test
```
