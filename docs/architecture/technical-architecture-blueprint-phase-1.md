# Technical Architecture Blueprint - Phase 1

## Purpose

This blueprint defines the foundation architecture for the SIRI Education platform, based on the codebase inventory originally captured in `docs/archive/phases/phase-1-foundation-inventory.md` (historical).

The application is an existing Laravel 13 modular monolith with Filament administration, Blade/Livewire public frontend, CMS, booking, settings, media, permissions, activity logging, and transactional email foundations already present. Future work must enhance these foundations instead of rebuilding them.

## Architecture Style

### Domain-Driven Modular Monolith

The project is a single Laravel application organized into domain-oriented modules. Domains are separated by folders, service providers, contracts, services, repositories, DTOs, events, policies, settings, Filament resources, Livewire components, and tests where the codebase already provides those boundaries.

The application must remain a modular monolith unless a future architecture decision explicitly says otherwise.

### Single Laravel Application

The public website, student portal, instructor profile area, admin panel, CMS, booking engine, payment workflow, notifications, and background jobs all live in the same Laravel application.

Do not create:

- A separate SPA for the public frontend.
- A second Laravel app for admin/frontend separation.
- Separate microservices for Phase 1 modules.
- Parallel API-only implementations when Blade/Livewire already exists.

### Business Logic Placement

Business rules belong in Actions, Services, Repositories, Policies, Settings, Jobs, Events, and Listeners.

Thin layers:

- Controllers validate/delegate/respond.
- Livewire components manage interaction state and delegate to services.
- Filament resources define admin UI and delegate lifecycle work to services/actions.
- Blade views render data only.
- Models define persistence, relationships, casts, scopes, media collections, and lightweight accessors.

### Cross-Domain Communication

Use events and queued listeners for cross-domain effects.

Examples already present:

- Booking lifecycle events in `app/Booking/Events`.
- Booking notifications in `app/Listeners/Booking/SendBookingNotifications.php`.
- Booking audit trail in `app/Listeners/Booking/RecordBookingLifecycleAudit.php`.
- Auth events in `app/Events/Auth`.
- Mail status listeners in `app/Listeners/Mail`.
- Activity-created notification bridge in `app/Listeners/NotifyAdminsOnActivity.php`.

When one domain needs another domain to react, prefer:

```
Domain service -> domain event -> queued listener -> target service/notification/audit
```

Do not place cross-domain side effects directly inside controllers, Filament resources, or Livewire components.

## Technology Baseline

Current source-of-truth versions are in `docs/architecture/overview.md` and `composer.json`.

| Concern         | Current implementation                           |
| --------------- | ------------------------------------------------ |
| Framework       | Laravel 13                                       |
| Admin           | Filament v5.6 admin panel at `/admin`            |
| Frontend        | Blade, Livewire 4, Alpine.js, Tailwind CSS, Vite |
| Permissions     | Spatie Permission and Filament Shield            |
| Settings        | Spatie Laravel Settings                          |
| Media           | Spatie Media Library                             |
| Activity        | Spatie Activitylog                               |
| Navigation tree | Kalnoy NestedSet                                 |
| Mail provider   | Resend in production, log/array locally          |

Compatibility note: this codebase currently uses Filament v5.6. New documentation and implementation should stay aligned with the installed version while keeping resource/page patterns conventional enough to avoid unnecessary coupling to a major-version-specific workaround.

## Request Lifecycle Patterns

### Public CMS Page

```
Route
  -> PageController
  -> PageService / PageRenderService
  -> ContentBlockService / BlockRenderer
  -> Blade layout and block components
```

Primary files:

- `routes/web.php`
- `app/Http/Controllers/PageController.php`
- `app/Services/PageService.php`
- `app/Services/PageRenderService.php`
- `app/Services/BlockRenderer.php`
- `app/Content/Models/ContentBlock.php`
- `resources/views/components/blocks/*`

### Admin Resource

```
Filament Resource
  -> Form/Table schema classes
  -> Policy / Gate
  -> Service/Action
  -> Repository/Model where applicable
  -> Activity log/notifications through events or services
```

Primary files:

- `app/Filament/Resources/*`
- `app/Policies/*`
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Providers/AppServiceProvider.php`

### Livewire Interaction

```
Livewire component
  -> validates state
  -> calls service/action
  -> receives DTO/array/result
  -> updates UI state
```

Livewire must not directly implement booking, payment, profile, permission, CMS rendering, notification, or audit rules.

### Booking Flow

```
Booking UI/API
  -> BookingWizardService / GuestBookingService / StudentBookingService
  -> BookingServiceInterface
  -> BookingRepositoryInterface
  -> Booking events
  -> queued listeners for notifications/audit/payment sync
```

Primary files:

- `app/Livewire/Frontend/Booking/BookingWizard.php`
- `app/Booking/Services/BookingWizardService.php`
- `app/Booking/Services/GuestBookingService.php`
- `app/Booking/Services/StudentBookingService.php`
- `app/Booking/Services/BookingService.php`
- `app/Booking/Contracts/*`
- `app/Booking/Repositories/*`
- `app/Booking/Events/*`

### Auth and Portal Flow

Portal routing must go through `app/Services/PortalResolver.php`.

`PortalResolver` owns:

- `usesAdminPortal(User)`
- `usesFrontendPortal(User)`
- `loginRedirect(User)`
- `logoutRedirect(User)`
- `dashboardRoute(User)`
- `homeRoute(User)`

Do not duplicate portal branching in controllers, middleware, views, policies, or Filament resources.

### Transactional Email Flow

```
Domain event/action
  -> queued listener
  -> queued notification
  -> configured mailer
  -> email_logs
  -> Resend webhook reconciliation
```

Primary files:

- `app/Services/Mail/TransactionalNotificationService.php`
- `app/Services/Mail/EmailLogService.php`
- `app/Models/EmailLog.php`
- `app/Filament/Resources/EmailLogs/EmailLogResource.php`
- `app/Listeners/Mail/*`
- `config/mail.php`
- `config/resend.php`

## Core Architectural Rules

### Settings Over Hardcoding

Use Spatie Settings for configurable application behavior.

Existing settings classes:

- `app/Settings/GeneralSettings.php`
- `app/Settings/SeoSettings.php`
- `app/Settings/MailSettings.php`
- `app/Settings/BookingSettings.php`
- `app/Settings/*Security*`
- `app/Settings/*Payment*`

New settings defaults belong in `database/settings`, not normal schema migrations.

### Policies for Access Control

Authorization belongs in policies and gates.

- Resource permissions use Shield naming such as `ViewAny:Booking`.
- Custom operational permissions use dotted names such as `security.authentication.update`.
- `Gate::before()` in `app/Providers/AppServiceProvider.php` grants super admin access globally.
- Do not duplicate super-admin checks in individual policies.

### Activitylog for Traceability

All business audit logs should flow through `app/Services/AuditTrailService.php`.

New business code must not call `activity()` directly. Existing raw calls are legacy alignment targets and should be migrated gradually when touched.

### Events for Cross-Domain Effects

Use explicit events/listeners because event auto-discovery is disabled in `app/Providers/EventServiceProvider.php`.

Any new event/listener must be registered there unless it belongs to a package/provider-specific mechanism.

### Queue Heavy Work

Notifications, webhook processing, audit side effects, payment callbacks, and long-running work should use queues/jobs/listeners.

Existing queue-related files:

- `database/migrations/0001_01_01_000002_create_jobs_table.php`
- `routes/console.php`
- `app/Jobs/Payments/ProcessPaymentWebhookJob.php`
- queued listeners in `app/Listeners`.

## Module Boundaries

| Module             | Boundary rule                                                                                   |
| ------------------ | ----------------------------------------------------------------------------------------------- |
| Auth/Security      | Use auth services/actions/settings. Keep route closures and controllers thin.                   |
| Portal             | Use `PortalResolver` only for portal selection.                                                 |
| CMS                | Use `Page`, `Post`, `ContentBlock`, render services, and block components.                      |
| Navigation         | Use `app/Navigation` services, drivers, and repository.                                         |
| Booking            | Use `app/Booking` contracts/services/repositories/actions/DTOs/events.                          |
| Payments           | Use booking payment services and provider registry.                                             |
| Forms              | Use `PublicFormService` and `PublicFormSubmissionRepository`.                                   |
| Profile            | Use profile services/actions and Spatie Media Library collections.                              |
| Student portal     | Use existing frontend layout/composer, Livewire components, booking/homework services.          |
| Instructor         | Use `InstructorService`, `User`, `UserProfile`, and existing teacher-named availability tables. |
| Settings           | Use Spatie Settings classes and Filament settings pages.                                        |
| Notifications/Mail | Use Laravel notifications, mail services, events/listeners, and email logs.                     |
| Audit              | Use `AuditTrailService`, Activity model, observers, and notification mapper.                    |

## Current Alignment Gaps

The current structure is mature but not perfectly uniform. These are gradual alignment targets, not reasons to rebuild:

- Some policies still call `$user->can(...)`; standards prefer `hasPermissionTo()` with `PermissionDoesNotExist` handling.
- Some route closures perform user lookup/status updates; move into controllers/actions when touched.
- Some Filament resources/pages perform direct model updates for admin actions; move to services when logic grows.
- `app/Livewire/Navigation/MenuBuilder.php` performs direct CMS/navigation lookups; move searches/deletes behind navigation services.
- Internal DB/domain naming still uses `Teacher*` while public-facing wording prefers instructor.
- Some legacy raw `activity()` calls remain outside `AuditTrailService`.

## Phase 1 Migration Policy

Before adding a migration:

1. Check existing migrations.
2. Check existing models and casts.
3. Check settings classes and `database/settings`.
4. Check whether Spatie Media Library already owns the data.
5. Check whether the concept belongs in an existing JSON/settings field.
6. Add only missing fields, not duplicate tables.

Do not recreate these existing tables:

- `users`
- `user_profiles`
- `pages`
- `posts`
- `content_blocks`
- `navigation_menus`
- `navigation_items`
- `bookings`
- `booking_types`
- `teacher_availability`
- `teacher_unavailability`
- `teacher_subjects`
- `settings`
- `permissions`, `roles`, and pivots
- `media`
- `activity_log`
- `email_logs`
- `countries`
- `states`
