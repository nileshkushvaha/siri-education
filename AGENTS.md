# Enterprise App

## Stack

- Laravel 13.18.1 (`^13.8`) · PHP `^8.3` (local 8.5.7) · MySQL 8+
- Filament 5.6.8 (`^5.6`) · Admin panel at `/admin`
- Livewire 4.3.3 · Alpine.js 3.14.3 · Tailwind CSS 4.3.1 · Vite 8.1.0
- Spatie Permission 7.4.2 · Spatie Activitylog 5.0.0 · Spatie Laravel Settings 3.9.0 · Spatie Media Library 11.23.1
- Kalnoy NestedSet 7.0.0 (navigation tree)

## Architecture

```
Controller → FormRequest → Service → Repository → Model
```

## Rules

- Never modify vendor packages or package migrations
- Keep Filament Resources thin — logic belongs in Services
- Services contain business logic
- Repositories contain database queries
- Policies handle authorization
- Activity Log is the Audit Trail — use `AuditTrailService`, never `activity()` directly in business code
- Notifications originate from the Activity Log pipeline, never from Services directly
- Queue heavy work
- Reuse existing Services, Repositories, Policies, Settings before creating new ones
- No duplicate business logic

## Portal Architecture

`PortalResolver` (`app/Services/PortalResolver.php`) is the single source of truth for portal selection.

Portals:
- **Admin Portal** — Filament `/admin` — `super_admin`, `manager`
- **Frontend Portal** — Blade `/dashboard` — `instructor`, `student`, future roles

Responsibilities owned exclusively by `PortalResolver`:
- `usesAdminPortal(User)` / `usesFrontendPortal(User)`
- `loginRedirect(User)` — where to send after successful login
- `logoutRedirect(User)` — where to send after logout
- `dashboardRoute(User)` / `homeRoute(User)`

Do not duplicate portal logic in controllers, middleware, policies, Filament providers, or Blade views. Every routing decision that branches on portal membership must call `PortalResolver`.

The only role helper permitted outside `PortalResolver` is `User::isSuperAdmin()` — used by `Gate::before()` and authorization policies. Do not add `isManager()`, `isInstructor()`, `isStudent()`, or similar helpers; all other portal and business-role checks go through `PortalResolver` or Spatie permissions directly.

Portal selection (`WHERE` a user goes) and authorization (`WHAT` a user may do) are separate concerns. Policies and permissions never decide portal; `PortalResolver` never decides permissions.

## Before coding

1. Read `docs/index.md`
2. Read only the relevant module doc
3. Make the smallest possible change
4. Do not refactor unrelated code
