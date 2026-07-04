# Enterprise App

An enterprise-grade starter kit built on **Laravel 13** and **Filament v5** — covering authentication, CMS, navigation, security configuration, payment infrastructure, and system monitoring. Designed as the stable foundation for business modules (e-commerce, LMS, etc.).

---

## Tech Stack

| | |
|---|---|
| **Language** | PHP `^8.3` (local runtime: 8.5.7) |
| **Framework** | Laravel 13.18.1 (`^13.8`) |
| **Admin Panel** | Filament 5.6.8 (`^5.6`, `/admin`) |
| **Database** | MySQL |
| **Frontend Runtime** | Blade · Livewire 4.3.3 · Alpine.js 3.14.3 · Tailwind CSS 4.3.1 · Vite 8.1.0 |
| **Permissions** | Spatie Laravel Permission 7.4.2 (`^7.0`) |
| **Settings** | Spatie Laravel Settings 3.9.0 (`^3.9`) |
| **Activity Log** | Spatie Laravel Activitylog 5.0.0 (`^5.0`) |
| **Media** | Spatie Laravel Media Library 11.23.1 (`^11.23`) |
| **Auth Shield** | Bezhansalleh Filament Shield 4.2.0 (`^4.2`) |

---

## Modules

### Auth
Email/password login · remember me · email verification · password reset · account lock after N failed attempts · self-service unlock via email · two-factor authentication (TOTP + recovery codes) · suspicious login detection · full login history

### Profile
Profile editing · avatar upload · password change · active session list with revoke

### Security (6 admin pages)
All settings are DB-backed, admin-configurable, and permission-gated with separate view/update permissions.

- **Authentication** — login methods, remember me, email verification
- **Password Policy** — min length, complexity, expiry, prevent-reuse history
- **Login Security** — max failed attempts, lockout duration, 2FA config
- **Session** — driver, lifetime, single-device mode, force-logout-all
- **Registration** — open/closed, allowed domains, default role assignment
- **Account Protection** — auto-lock threshold, suspicious login alerts

### CMS
- **Pages** — UUID PK, scheduled publishing, SEO fields, page templates, soft deletes
- **Posts** — author, categories, tags, related posts, featured image, scheduled publishing
- **Content Blocks** — 19 types: Hero · Rich Text · Image · Gallery · Video · CTA · FAQ · Accordion · Tabs · Team · Testimonials · Statistics · Timeline · Button · Divider · Spacer · Map · Contact Form · Contact Info
- **Navigation** — multi-menu system, 10 link types, role/permission-based item visibility, publish windows
- **Search** — full-text frontend search
- **SEO** — sitemap.xml, robots.txt, per-page meta/OG/JSON-LD
- **Media** — Spatie Media Library (avatars, featured images)
- **Contact form** — submission + email notification

### Settings (7 admin pages)
General · SEO · Mail (SMTP) · Payment Gateways · Payment Configuration · Payment Advanced · Bank Account

### System
- **Cache Manager** — clear/optimize by cache type, permission-gated
- **Scheduler Monitor** — task list, run on-demand, 30-day history, mutex detection, cron descriptions
- **Queue Monitor** — pending/failed job counts by queue

### Payment Infrastructure (scaffolded, not yet activated)
Webhook controller, signature verification, and job queue for Stripe · Razorpay · PayPal · Cashfree · PayU · PhonePe · Manual. Gateway settings pages are fully built. Order/product logic is a future module.

---

## Quick Start

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

Admin panel at **`/admin`**. Log in with the super admin credentials from `SuperAdminSeeder`.

---

## Tests

```bash
composer test
```

Always use `composer test` — it enforces `--env=testing` and targets `enterprise_app_testing`. Running `php artisan test` bare risks the development database. A safety guard in `TestCase` will abort before any damage, but use the composer script as the habit.

```bash
# Subset
php artisan test --env=testing --filter SecurityPolicyTest
php artisan test --env=testing tests/Feature/Security/
```

Current suite: **1460 tests, 1460 passing**.

---

## Admin Panel Structure

| Navigation Group | Contains |
|---|---|
| Administration | Users, Roles, Permissions |
| CMS | Pages, Posts, Post Categories, Tags, Content Blocks |
| Masters | Countries |
| Configuration | General, SEO, Mail settings |
| Payment | Gateway, Configuration, Advanced, Bank Account settings |
| Security | Authentication, Password Policy, Login Security, Session, Registration, Account Protection |
| System | Cache Manager, Scheduler Monitor, Queue Monitor, Activity Log |

---

## Artisan Commands

```bash
php artisan app:doctor                    # health check (dev)
php artisan app:doctor --env=testing      # health check (test)
php artisan migrate --path=database/settings   # run settings migrations
php artisan shield:generate --all         # regenerate Shield permissions after new resources
```

---

## Documentation

| | |
|---|---|
| [Architecture](docs/Architecture.md) | Module map, directory layout, service providers, data flow, DB schema |
| [Development Guide](docs/Development-Guide.md) | Local setup, daily commands, adding resources and settings pages |
| [Coding Standards](docs/Coding-Standards.md) | PHP and Filament conventions, Gate patterns, naming rules |
| [Permission Matrix](docs/Permission-Matrix.md) | Every Gate ability, role definitions, policy map, Shield permissions |
| [Settings Architecture](docs/Settings-Architecture.md) | All 13 settings classes and groups, how to add a new settings group |
| [CMS Architecture](docs/CMS-Architecture.md) | Content bounded context, block type system, rendering pipeline |
| [Testing](docs/Testing.md) | Safety guard, test commands, settings test patterns, common pitfalls |
| [Roadmap](docs/Roadmap.md) | What's in v1.0, future modules, known technical debt |
