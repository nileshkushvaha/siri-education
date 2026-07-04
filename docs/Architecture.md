# Architecture

## Stack

| Layer | Technology | Composer/npm constraint | Installed |
|---|---|---|---|
| Language | PHP | `^8.3` | 8.5.7 local CLI |
| Framework | Laravel | `^13.8` | 13.18.1 |
| Admin panel | Filament | `^5.6` | 5.6.8 |
| Frontend interactivity | Livewire | transitive via Filament | 4.3.3 |
| Frontend behavior | Alpine.js | CDN-pinned | 3.14.3 |
| CSS | Tailwind CSS / `@tailwindcss/vite` | `^4.3.1` | 4.3.1 |
| Asset bundler | Vite / Laravel Vite plugin | `^8.0.0` / `^3.1` | 8.1.0 / 3.1.0 |
| Database | MySQL | — | MySQL 8+ expected |
| Auth/permissions | Spatie Laravel Permission | `^7.0` | 7.4.2 |
| Settings | Spatie Laravel Settings | `^3.9` | 3.9.0 |
| Activity log | Spatie Laravel Activitylog | `^5.0` | 5.0.0 |
| Media | Spatie Laravel Media Library | `^11.23` | 11.23.1 |
| Query builder | Spatie Laravel Query Builder | `^7.3` | 7.3.0 |
| Slugs | Spatie Laravel Sluggable | `^4.0` | 4.0.2 |
| Navigation tree | Kalnoy NestedSet | `^7.0` | 7.0.0 |
| Filament Shield | bezhansalleh/filament-shield | `^4.2` | 4.2.0 |
| Testing | PHPUnit | `^12.5.12` | 12.5.30 |

Admin panel lives at `/admin`. Frontend is standard Laravel + Blade.

---

## Module Map

```
enterprise-app/
├── Auth module          — login, register, 2FA, password reset, account lock
├── Profile module       — user profile, password change, sessions, avatar
├── CMS module           — Pages, Posts, Content Blocks, Navigation, Media
├── Security module      — 6 settings pages with permission-gated access
├── Settings module      — 7 setting groups (general, mail, SEO, payment×4)
├── System module        — Cache Manager, Scheduler Monitor, Queue Monitor
└── Admin panel          — Filament v5, path=/admin
```

---

## Directory Structure

```
app/
├── Actions/            — single-purpose command objects (RegisterUserAction, etc.)
│   ├── Auth/
│   └── Profile/
├── Console/Commands/   — artisan commands (PublishScheduledContent, DoctorCommand)
├── Content/            — CMS bounded context (isolated namespace: App\Content\)
│   ├── Contracts/      — HasContentBlocks interface
│   ├── Models/         — ContentBlock model
│   ├── Rendering/      — ContentRenderer (abstract base, extended by PageRenderService)
│   ├── SEO/            — SeoManager
│   └── Services/       — ContentBlockService
├── Enums/              — PHP 8.1 backed enums (BlockType, PageStatus, LoginResult, etc.)
├── Events/Auth/        — UserRegistered, UserLoggedIn, UserLoggedOut, LoginFailed
├── Filament/
│   ├── Pages/          — non-resource pages (Dashboard, CacheManager, Security/*, Settings/*)
│   ├── Resources/      — Filament CRUD resources (auto-discovered)
│   └── Widgets/        — StatsOverview, RecentUsers, RecentLogins
├── Forms/Blocks/       — one class per block type for the BlockFormSchemaFactory
├── Http/
│   ├── Controllers/    — frontend + auth controllers
│   ├── Middleware/     — TrackUserSession
│   └── Requests/       — Form Requests (Auth, Profile)
├── Jobs/Payments/      — ProcessPaymentWebhookJob
├── Listeners/Auth/     — LogLoginActivity, SendRegistrationNotifications, SendWelcomeNotification
├── Livewire/           — Navigation/MenuBuilder (Livewire component)
├── Models/             — Eloquent models
├── Navigation/         — navigation rendering system (see Navigation-Architecture section)
├── Notifications/      — Auth (6 notifications), Cms (ContactFormSubmission)
├── Observers/          — Page, Post, PostCategory, Tag, ContentBlock
├── Policies/           — Gate policies; Security/ subdirectory
├── Providers/          — AppServiceProvider, CmsServiceProvider, EventServiceProvider,
│   │                     NavigationServiceProvider, Filament/AdminPanelProvider
├── Services/
│   ├── Auth/           — LoginService, RegistrationService, PasswordResetService, TwoFactorService
│   ├── Payment/        — PaymentWebhookProcessor, PaymentWebhookSignatureService, etc.
│   ├── Permission/     — PermissionGroupingService
│   ├── Profile/        — ProfileService, SessionService
│   └── Security/       — SecuritySettingsService, PasswordRuleBuilder, AdminSessionService
├── Settings/           — 13 Spatie Settings classes (see Settings-Architecture.md)
└── Support/            — PermissionLabelFormatter, UserAgentParser
```

---

## Service Providers

| Provider | Responsibility |
|---|---|
| `AppServiceProvider` | Gate policies, Gate::before (super_admin), Permission observer, model observers, scheduler history listeners |
| `CmsServiceProvider` | Morph map, ContentBlock observer, ContentBlock policy, PageRenderService singleton |
| `EventServiceProvider` | Auth event → listener wiring (auto-discovery disabled) |
| `NavigationServiceProvider` | Navigation link type drivers, services, cache manager |
| `AdminPanelProvider` | Filament panel config: `/admin` path, navigation groups, auto-discover resources/pages/widgets |

---

## Data Flow — Page Request

```
Request → Router (web.php)
       → PageController::home() / show()
       → PageService::getPublishedPage($slug)
       → PageRenderService::render($page)        ← extends ContentRenderer
       → ContentBlockService::getBlocksForPage()
       → BlockRenderer::render($block)            ← per BlockType
       → Blade view ('frontend.page') with $html
```

## Data Flow — Admin Panel

```
Browser → /admin/* → Filament routing
        → AdminPanelProvider middleware stack
          (web, TrackUserSession, auth:web, verified, ...)
        → FilamentUser::canAccessPanel()          ← on User model
        → Resource/Page canAccess() / canView()
        → Gate::authorize() inside save() methods
```

## Data Flow — Auth

```
POST /login → LoginController → LoginService
            → LoginService checks: locked? blocked? inactive? 2FA?
            → dispatches UserLoggedIn event
            → LogLoginActivity listener writes LoginHistory
```

---

## Navigation System

The navigation system is a standalone bounded context in `app/Navigation/`:

- `NavigationManager` — top-level orchestrator, resolves menus by location
- `NavigationRepository` — Eloquent queries, eager-loads items+roles+permissions
- `NavigationRenderer` — converts DB tree to HTML via Blade components
- `NavigationCacheManager` — invalidates/warms cache (tagged: `navigation`)
- `NavigationItemService` — resolves `is_active` state for current URL
- `PermissionEvaluator` — visibility checks (roles/permissions/publish windows)
- `UrlResolver` + `Drivers/` — 10 link type drivers (Page, Post, Route, External, etc.)

Navigation menus are created in admin under `/admin/navigation-menus`. Items support role/permission visibility, publish windows, and 10 link types.

---

## Scheduled Tasks

Defined in `routes/console.php`:

| Command | Schedule | Log |
|---|---|---|
| `PublishScheduledContent` | Every minute, `withoutOverlapping()` | `logs/scheduled-publishing.log` |
| `model:prune --model=SchedulerHistory` | Daily | `logs/model-prune.log` |
| `activitylog:clean` | Weekly | `logs/activitylog-clean.log` |

`SchedulerHistory` records every task run via `ScheduledTaskFinished`, `ScheduledTaskFailed`, `ScheduledTaskSkipped` listeners in `AppServiceProvider`. Records prune after 30 days.

---

## Database Schema Overview

| Table | Description |
|---|---|
| `users` | Auth, status, lock fields, 2FA fields, login tracking |
| `user_profiles` | Extended profile (bio, social links, etc.) |
| `login_histories` | Every login attempt with IP, UA, result |
| `user_sessions` | Active sessions tracked by `TrackUserSession` middleware |
| `user_password_histories` | Previous password hashes (for prevent-reuse policy) |
| `pages` | Static pages (UUID PK, soft deletes, SEO fields, scheduled publish) |
| `content_blocks` | Polymorphic blocks owned by pages or posts |
| `posts` | Blog posts (UUID PK, author, categories, tags, related posts) |
| `post_categories` | Hierarchical (parent_id) |
| `tags` | Flat tag list |
| `navigation_menus` | Menu containers (name, location) |
| `navigation_items` | Tree structure (parent_id, sort_order, link_type, publish window) |
| `navigation_item_roles` | Pivot: item visibility by role |
| `navigation_item_permissions` | Pivot: item visibility by permission |
| `countries` | Reference data |
| `settings` | Spatie settings key-value store (all 13 setting groups) |
| `activity_log` | Spatie activitylog events |
| `permissions` / `roles` / pivots | Spatie permission tables |
| `media` | Spatie Media Library |
| `scheduler_histories` | Task run records (MassPrunable, 30-day TTL) |
| `cache` / `jobs` / `sessions` | Laravel framework tables |
