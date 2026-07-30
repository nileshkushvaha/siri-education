# Development Guide

## Prerequisites

- PHP 8.3+ (project runs 8.5.7)
- MySQL 8+
- Composer 2
- Node.js (for asset compilation)

---

## First-Time Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create databases
mysql -uroot -p -e "CREATE DATABASE enterprise_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p -e "CREATE DATABASE enterprise_app_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations (dev)
php artisan migrate

# Seed settings into the settings table
php artisan migrate --path=database/settings

# Seed roles, permissions, and super_admin user
php artisan db:seed --class=SuperAdminSeeder

# (Optional) seed demo navigation
php artisan db:seed --class=NavigationSeeder

# Build frontend assets
npm install && npm run build
```

---

## Essential Commands

### Running the App

```bash
php artisan serve                     # dev server
npm run dev                           # vite dev mode (HMR)
php artisan queue:work                # process queued jobs (mail, notifications)
php artisan schedule:work             # run scheduler locally (every minute)
```

### Tests

```bash
composer test                         # always use this — runs --env=testing
php artisan test --env=testing --filter ClassName
php artisan test --env=testing --filter "ClassName::test_method"
```

Never use `php artisan test` bare — it runs against the dev database. See [testing.md](testing.md).

### Settings Migrations

Settings are stored in the `settings` table via Spatie Laravel Settings. They have their own migration path:

```bash
# Run all settings migrations
php artisan migrate --path=database/settings

# Create a new settings migration
php artisan make:settings-migration fill_my_settings
```

Settings migrations live in `database/settings/`, not `database/migrations/`.

### Health Check

```bash
php artisan app:doctor                # verify dev environment
php artisan app:doctor --env=testing  # verify test environment
```

### Permissions

```bash
# After adding new Filament Resources (generates Shield permissions)
php artisan shield:generate --all

# Regenerate for a specific resource
php artisan shield:generate --resource=PostResource
```

### Other Useful Commands

```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:list --path=admin   # list admin routes
php artisan tinker                    # REPL
```

---

## Admin Panel

- URL: `/admin`
- Login with the super_admin user created by `SuperAdminSeeder`
- Navigation groups (in order): Administration, CMS, Masters, Configuration, Payment, Security, System
- Resources auto-discovered from `app/Filament/Resources/`
- Pages auto-discovered from `app/Filament/Pages/`

---

## Adding a New Filament Resource

1. Create resource class in `app/Filament/Resources/{Name}/`
2. Create sub-files: `Pages/`, `Schemas/`, `Tables/` (follow existing pattern, e.g. `PostResource`)
3. Run `php artisan shield:generate --resource={Name}Resource` to create permissions
4. Add the new permissions to `PermissionGroupingService` if needed for grouping in the Roles UI
5. The resource is auto-discovered — no manual registration required

## Adding a New Settings Page

1. Create a settings class in `app/Settings/` extending `Spatie\LaravelSettings\Settings`
2. Implement `public static function group(): string` returning a unique group name
3. Create a settings migration in `database/settings/` using `make:settings-migration`
4. Create a Filament page in `app/Filament/Pages/Settings/` using `HasSettingsAccess` trait
5. Run `php artisan migrate --path=database/settings`

See [settings.md](../settings.md) for the full pattern.

---

## Environment Files

| File | Purpose |
|---|---|
| `.env` | Development (DB: `enterprise_app`, cache: database, mail: smtp) |
| `.env.testing` | Tests (DB: `enterprise_app_testing`, cache: array, mail: array) |

**Never modify** `bootstrap/app.php`, `bootstrap/cache/`, PHPUnit bootstrap, or the dotenv loading sequence. See the incident note in memory.

---

## Asset Pipeline

Assets are compiled with Vite. Filament compiles its own assets separately.

```bash
npm run dev    # development (HMR)
npm run build  # production build → public/build/
```

Filament CSS lives in `public/css/filament/` and `public/fonts/filament/` — these are published by Filament and should not be edited.

---

## Queue & Notifications

All notifications (`ShouldQueue`) dispatch to the `notifications` queue. Run a worker:

```bash
php artisan queue:work --queue=notifications,default
```

In development, set `QUEUE_CONNECTION=sync` in `.env` to process synchronously (notifications fire immediately, no worker needed).

---

## Logging

Logs write to `storage/logs/laravel.log` by default. Scheduled task output goes to dedicated log files:

- `storage/logs/scheduled-publishing.log`
- `storage/logs/model-prune.log`
- `storage/logs/activitylog-clean.log`
