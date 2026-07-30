# Permission Matrix

## Authorization standard

The `roles` table is the single source of truth for authorization. **Role IDs and user IDs are never compared anywhere in the codebase** — only role *name* and Spatie permissions matter.

There is exactly one helper that may determine Super Admin status:

```php
User::isSuperAdmin(): bool   // app/Models/User.php — return $this->hasRole('super_admin');
```

No other method, enum, config value, or constant defines "super admin." Every authorization path — `Gate::before()`, all Policies, `DashboardResolver`, Filament Pages (Settings, Security, Cache Manager, Scheduler Monitor, Queue Monitor), and notification recipient lookups — calls `$user->isSuperAdmin()`. Do not reintroduce a role enum or hardcoded role-ID/user-ID check; if you find one, it's a regression.

Only Super Admin bypasses permission checks. Every other role — including `manager` — gets access exclusively through Policies and assigned Spatie permissions; there is no second automatic-access role.

## Roles

| Role | Description | How granted |
|---|---|---|
| `super_admin` | Unconditional access to everything via `Gate::before()` | Assigned by `SuperAdminSeeder` (to user ID 1, seed-time only — not a runtime authorization rule) |
| `manager` | Read access across admin resources | Assigned via `DefaultRolesAndUsersSeeder`; access is entirely permission-driven, no automatic bypass |
| `instructor` | Manage CMS content | Assigned via `DefaultRolesAndUsersSeeder`; permission-driven |
| `student` | Default frontend role | Assigned on registration via `RegistrationSettings::default_role` |
| Custom roles | Admin-configurable | Created in admin under Roles resource |

Super admin bypass is implemented in `AppServiceProvider::registerSuperAdminGate()`, which calls `$user->isSuperAdmin()` — looked up by role name, never by ID. **New permissions are automatically assigned to super_admin** via the `Permission::created` observer in the same provider, which looks the role up by `name` (`Role::where('name', 'super_admin')->first()`), not by ID.

---

## Gate Abilities (Named Gates)

All defined in `AppServiceProvider::registerPolicies()`.

### Profile

| Ability | Policy method | Who can |
|---|---|---|
| `profile.view` | `ProfilePolicy::view` | Owner |
| `profile.update` | `ProfilePolicy::update` | Owner |
| `password.change` | `ProfilePolicy::changePassword` | Owner |

### Security

| Ability | Policy method |
|---|---|
| `security.authentication.view` | `SecurityPolicy::viewAuthentication` |
| `security.authentication.update` | `SecurityPolicy::updateAuthentication` |
| `security.password_policy.view` | `SecurityPolicy::viewPasswordPolicy` |
| `security.password_policy.update` | `SecurityPolicy::updatePasswordPolicy` |
| `security.login_security.view` | `SecurityPolicy::viewLoginSecurity` |
| `security.login_security.update` | `SecurityPolicy::updateLoginSecurity` |
| `security.session.view` | `SecurityPolicy::viewSession` |
| `security.session.update` | `SecurityPolicy::updateSession` |
| `security.registration.view` | `SecurityPolicy::viewRegistration` |
| `security.registration.update` | `SecurityPolicy::updateRegistration` |
| `security.account_protection.view` | `SecurityPolicy::viewAccountProtection` |
| `security.account_protection.update` | `SecurityPolicy::updateAccountProtection` |

### System Tools

| Ability | Policy method |
|---|---|
| `cache_manager.view` | `CacheManagerPolicy::viewPage` |
| `cache_manager.clear` | `CacheManagerPolicy::clearApplicationCache` |
| `cache_manager.optimize` | `CacheManagerPolicy::optimize` |
| `scheduler_monitor.view` | `SchedulerMonitorPolicy::viewPage` |
| `scheduler_monitor.run` | `SchedulerMonitorPolicy::runTask` |
| `queue_monitor.view` | `QueueMonitorPolicy::viewPage` |

---

## Model Policies (Gate::policy)

| Model | Policy class | Registered in |
|---|---|---|
| `User` | `UserPolicy` | Laravel auto-discovery (`App\Models\User` → `App\Policies\UserPolicy`) — **do not** bind `User::class` to `ProfilePolicy` via `Gate::policy()`; `ProfilePolicy` only implements `view`/`update`/`changePassword` for the separate `profile.*` named gates and would shadow `UserPolicy`'s CRUD checks, causing Filament to default-allow full User management to any authenticated user |
| `NavigationMenu` | `NavigationMenuPolicy` | `AppServiceProvider` |
| `Activity` (activitylog) | `ActivityLogPolicy` | `AppServiceProvider` |
| `Spatie\Permission\Models\Role` | `RolePolicy` | `AppServiceProvider` |
| `Spatie\Permission\Models\Permission` | `PermissionPolicy` | `AppServiceProvider` |
| `ContentBlock` | `ContentBlockPolicy` | `CmsServiceProvider` |

> Models outside the `App\Models` namespace (Spatie's `Role`, `Permission`) **must** be explicitly registered via `Gate::policy()` — Laravel's convention-based auto-discovery only looks inside the model's own namespace tree, so it never finds an `App\Policies\*` class for them. If a Filament Resource is backed by an unregistered or method-incomplete policy, Filament does not deny by default — `get_authorization_response()` in `vendor/filament/filament/src/helpers.php` falls through to `Gate::before()` and defaults to **allow** if nothing else answers. Adding a new Resource over a non-`App\Models` model always needs a matching `Gate::policy()` registration here.

---

## Custom Permission: AssignPermissions:Role

`AssignPermissions:Role` is not a Shield-generated CRUD verb — it's a hand-seeded permission (in `SuperAdminSeeder`) that separately gates the "Permission Assignment" matrix on the Role create/edit form (`app/Filament/Resources/Roles/Schemas/RoleForm.php`) from `Update:Role`/`Create:Role`. A user can be able to edit a role's name/status/description without being able to see or change which permissions it grants.

Enforced in two places, both required:
- **UI**: `RoleForm`'s "Permission Assignment" `Section` is `->visible()` only for users with this permission.
- **Server-side (the actual boundary)**: `EditRole::afterSave()` / `CreateRole::afterCreate()` only call `syncPermissions()` when the acting user has this permission. `selectedPermissions` is a public Livewire property (Alpine-entangled), so hiding the UI section alone does not stop a tampered request — the mutation itself is guarded.

Grant via the Role's own Permission Assignment matrix (module: "Role Management" → "Assign Permissions"), same as any other permission.

---

## Shield-Generated Permissions

Filament Shield generates one permission per Filament Resource ability, in the format **`{Ability}:{ModelName}`** — e.g. `ViewAny:Booking`, `Create:Wallet`, `Update:Role`. The separator is `:` (configured in `config/filament-shield.php`'s `separator` key — the package default is `_`, this project overrides it). These are stored in the `permissions` table and assignable to roles.

There are 55+ Filament Resources today, each contributing several ability permissions — too many to keep as a manually maintained list here without it drifting immediately. To see the full current list:

```bash
php artisan permission:show
```

or query the `permissions` table directly. Policies read these with `$user->hasPermissionTo('Ability:Model')` (see `docs/architecture/code-standards.md`'s Policy Pattern for the standard `hasPermission()` wrapper every policy uses).

After adding a new Resource, regenerate its permissions:

```bash
php artisan shield:generate --resource=MyNewResource
```

---

## How Access Control Layers Work

For a Security settings page, there are three layers:

```
Layer 1: FilamentUser::canAccessPanel()    — must be active + verified (or super_admin)
Layer 2: HasSecurityAccess::canAccess()    — checks security.{page}.view permission
                                             (hides page from sidebar if denied)
Layer 3: Gate::authorize() in save()       — checks security.{page}.update permission
                                             (blocks write even if page is visible)
```

This means a user can have view-only access to a Security page (read settings, cannot save).

For Filament Resources, the standard Filament policy methods (`canViewAny`, `canCreate`, etc.) map to Shield permissions. See `ContentBlockPolicy` for the pattern.

---

## ContentBlock Permission Model

`ContentBlockPolicy` (`app/Policies/ContentBlockPolicy.php`) uses a polymorphic ownership model — permissions depend on whether the block belongs to a Page or a Post:

- Users with `update_page` permission can update/delete Page blocks
- Users with `update_post` permission can update/delete Post blocks
- `viewAny` and `create` require either `update_page` OR `update_post`

`tests/Feature/ContentBlockPolicyTest.php` covers this, including cross-ownership denial.

---

## Adding a New Gate Ability

1. Define in `AppServiceProvider::registerPolicies()`:
   ```php
   Gate::define('module.resource.action', [ModulePolicy::class, 'methodName']);
   ```
2. Add the policy method to the policy class
3. Create the permission record (if role-assignable) via a seeder or settings migration
4. Call `Gate::authorize('module.resource.action')` in the relevant controller/page method
