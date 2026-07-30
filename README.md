# Enterprise App

An enterprise-grade Laravel starter kit — authentication, CMS, navigation, security configuration, booking/scheduling, payments and payouts, wallet ledger, and system monitoring — built as a stable foundation for business modules.

## Stack

Laravel 13 · PHP 8.5 · MySQL · Filament v5.6 admin panel (`/admin`) · Blade/Livewire/Alpine/Tailwind frontend · Spatie Permission, Activitylog, Settings, Media Library · Kalnoy NestedSet.

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create databases
mysql -uroot -p -e "CREATE DATABASE enterprise_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p -e "CREATE DATABASE enterprise_app_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Migrations
php artisan migrate
php artisan migrate --path=database/settings

# Seed super admin + roles
php artisan db:seed --class=SuperAdminSeeder

# Assets
npm install && npm run build

# Run
php artisan serve
php artisan queue:work
```

Admin panel at `/admin` — log in with the super admin credentials from `SuperAdminSeeder`.

## Essential commands

```bash
composer test                                  # run the test suite (see Testing guide before using anything else)
php artisan app:doctor                         # environment health check
php artisan migrate --path=database/settings   # run settings migrations
php artisan shield:generate --all              # regenerate Shield permissions after new resources
```

## Documentation

Full documentation catalog: **[docs/index.md](docs/index.md)**.

Start there for architecture, domain reference, security, operations, integrations, and requirements docs. For local setup and testing conventions specifically, see [docs/development/guide.md](docs/development/guide.md) and [docs/development/testing.md](docs/development/testing.md) — testing in particular has a database-safety guard you should read before running anything other than `composer test`.
