# Filament Admin Foundation Alignment

## Executive Summary

This phase audited all 26 Filament v4 resources and ~20 pages against architecture, security, and consistency checks, then applied safe, additive fixes — no resource was rebuilt. The panel's navigation was reorganized from 7 ad-hoc groups into the 11 recommended groups. Two real defects were found and fixed: a pre-existing bug that made Role duplication crash outright, and a privilege-boundary gap where duplicating a role copied its full permission set without checking the permission that's supposed to gate permission assignment. Everything else was either already solid (policies, soft-delete handling, bulk-action safety on the pattern-setting resources) or a small, additive gap (missing `LogsActivity` on 8 models, missing global search on 15 resources, two minor validation gaps).

## Navigation Group Reorganization

The panel previously used 7 groups that had grown organically (`Administration`, `CMS`, `Masters`, `Configuration`, `Payment`, `Security`, `System`). Remapped to the 11 recommended groups — every existing resource/page kept its icon, sort order, and behavior; only the `$navigationGroup` string changed:

| New Group | Resources / Pages | Was |
|---|---|---|
| **Platform** | Countries, States, Currencies, Languages; General/Mail/SEO/Platform Foundation settings pages | Masters + Configuration |
| **Users & Access** | Users, Roles, Permissions; Authentication/Password Policy/Login Security/Session/Registration/Account Protection settings pages | Administration + Security |
| **Academic** | Academic Categories, Subjects, Academic Levels, Skill Levels | Masters |
| **Marketplace** | *(none yet — see below)* | — |
| **Scheduling** | Teacher Availability, Teacher Leave | Bookings |
| **Booking** | Bookings, Booking Types | Bookings |
| **Finance** | Payment Advanced/Bank Account/Configuration/Gateway settings pages | Payment |
| **Content** | Pages, Posts, Post Categories, Tags, Navigation, Page Blocks, FAQs, FAQ Categories | CMS |
| **Communication** | Email Logs | System |
| **Reports** | Booking Reports page | Bookings |
| **System** | Activity Log, Login History, Cache Manager, Queue Monitor, Scheduler | System (unchanged) |

**Marketplace is intentionally empty.** No existing resource is a marketplace-specific concern distinct from what's already classified elsewhere — instructor/student profile management lives in the Users & Access-classified `UserResource` (its Instructor/Student tabs), and instructor-facing settings (`InstructorSettings`, `ReferralSettings`, `WalletSettings`) are bundled into the single Platform Foundation settings page rather than split into their own pages. This group is reserved for when a genuinely marketplace-specific resource is built (e.g., a standalone instructor application/review queue) — it was not populated by force-splitting an existing page.

`Security` settings pages were folded into **Users & Access** rather than kept separate, since the recommended taxonomy has no standalone security group and password policy / login security / session management / registration are all mechanisms that control access — the same concern **Users & Access** already covers for Users/Roles/Permissions.

## Audit Findings

Every resource was checked for: duplicate resources, missing permissions/policies, business logic in the resource layer, missing policies, inconsistent navigation, missing global search, unsafe bulk actions, missing soft-delete handling, missing activity logging, missing validation.

### Confirmed clean, no changes needed

- **No duplicate resources.** Every model has exactly one Filament resource. `TeacherLeaveResource` intentionally maps to the `TeacherUnavailability` model (a deliberate naming choice predating this phase, documented in the model's docblock) — not a duplicate.
- **Soft deletes.** Every model using `SoftDeletes` already has a `TrashedFilter` and `RestoreAction`/`RestoreBulkAction` in its table. No gaps found.
- **Bulk action safety (pattern resources).** `UsersTable`'s bulk delete already excludes the acting user and super-admin accounts (`->reject(fn ($r) => $r->id === auth()->id() || $r->isSuperAdmin())`) — the right pattern, already in place.
- **Policies for Country/Language/ContentBlock.** Initially flagged by an automated pass as "missing," all three actually resolve correctly: `CountryPolicy`/`LanguagePolicy` exist and auto-discover normally, and `ContentBlockPolicy` (for the namespaced `App\Content\Models\ContentBlock`) was confirmed via `Gate::getPolicyFor()` to resolve correctly despite the extra namespace segment — Laravel's policy guesser handles this. No fix needed.
- **`LoginHistoryResource`'s direct permission checks** (`can('ViewAny:LoginHistory')` instead of a named policy class) are consistent with the app's established Shield-style convention (see `UserPolicy`, which does the same thing) — not a gap, just a valid alternative to a full policy class for a resource with few, simple authorization rules.

### Real gaps found and fixed

1. **8 models missing `LogsActivity`**: `AcademicCategory`, `Subject`, `AcademicLevel`, `SkillLevel`, `Faq`, `FaqCategory`, `TeacherAvailability`, `TeacherUnavailability`. All are genuine business entities with Filament CRUD, so all 8 got the trait plus a `getActivitylogOptions()` matching the existing project convention (`logOnly` the meaningful fields, `useLogName` matching the table name, `logOnlyDirty`, exclude `updated_at`-only changes). `EmailLog` and `LoginHistory` were confirmed correctly excluded — they're themselves append-only logs; logging changes to a log is not useful.

2. **15 resources missing global search.** Filament's global search defaults to a resource's `$recordTitleAttribute` if declared — 8 resources already had one (Users, Roles, Countries, States, Currencies, Languages, ActivityLog, LoginHistory). Added `$recordTitleAttribute` to the 14 that didn't: the 4 Academic resources, Booking Types, Bookings (`reference`), FAQ + FAQ Categories, Navigation, Pages, Permissions, Post Categories, Posts, Tags. `UserResource` additionally got a `getGloballySearchableAttributes()` override to search `name` **and** `email` — the single most common admin search pattern. Left out deliberately: Email Logs, Login History, Activity Log (append-only logs — global search across audit trails is unusual and better done via each resource's own filtered table), Page Blocks (blocks aren't independently titled; they're always found via their parent Page/Post), Teacher Availability/Leave (schedule slots have no natural single search term).

3. **A pre-existing bug: Role duplication crashed outright.** `RolesTable`'s "Duplicate" action failed with a SQL "unknown column" error on every use. Root cause: the table's `permissions_count`/`users_count` columns are `->counts()` aggregates (loaded via Filament's `withCount()`, not real columns), and Filament's `ReplicateAction` was copying every loaded attribute — including those two — into the new row's `INSERT`. Fixed with `->excludeAttributes(['permissions_count', 'users_count'])`, the mechanism Filament provides for exactly this case.

4. **A privilege-boundary gap in the same action.** Once duplication worked, `afterReplicaSaved` unconditionally copied the *original* role's full permission set onto the new role — an assignment, gated nowhere by `AssignPermissions:Role`, the same permission that already separately gates permission changes on Create/Edit Role (see the existing `RolePermissionAssignmentTest`). A user with only `Replicate:Role` (not `AssignPermissions:Role`) could effectively grant themselves any permission set via "Duplicate," bypassing the intended gate. Fixed by checking `AssignPermissions:Role` before syncing; without it, the replica is created with no permissions, consistent with how Create/Edit Role already behave for the same user.

5. **Two minor validation gaps.** `TagForm`'s `sort_order` had `->numeric()` but no `->minValue(0)` — added. `CurrencyForm`'s `numeric_code` (ISO 4217 numeric currency code, e.g. `840`) had no format validation at all — **not** fixed with `->numeric()` (that would silently strip significant leading zeros from codes like `008`), but with a `->regex('/^\d{3}$/')` 3-digit pattern instead, which is the actually-correct validation for this field.

## Reused vs. Changed — Full Resource Report

| Resource / Page | Status |
|---|---|
| Academic (Category/Subject/Level/Skill) | **Changed** — nav group, `LogsActivity`, `$recordTitleAttribute` |
| Activity Log | Reused as-is |
| Booking Types | **Changed** — nav group, `$recordTitleAttribute` |
| Bookings | **Changed** — nav group, `$recordTitleAttribute` |
| Countries / States / Currencies / Languages | **Changed** — nav group only (Currencies also got the `numeric_code` validation fix) |
| Email Logs | **Changed** — nav group only |
| FAQ / FAQ Categories | **Changed** — nav group, `LogsActivity`, `$recordTitleAttribute` |
| Login History | Reused as-is |
| Navigation | **Changed** — nav group, `$recordTitleAttribute` |
| Page Blocks | **Changed** — nav group only |
| Pages | **Changed** — nav group, `$recordTitleAttribute` |
| Permissions | **Changed** — nav group, `$recordTitleAttribute` |
| Post Categories / Posts / Tags | **Changed** — nav group, `$recordTitleAttribute`; Tags also got the `sort_order` validation fix |
| Roles | **Changed** — nav group, fixed the Duplicate action crash + its permission-sync gap |
| Teacher Availability / Teacher Leave | **Changed** — nav group, `LogsActivity` on the underlying models |
| Users | **Changed** — nav group, `getGloballySearchableAttributes()` (name + email) |
| Settings pages (all) | **Changed** — nav group reassignment only; form/save logic untouched (already covered by the Activity Audit Foundation phase's `LogsSettingsUpdates`) |
| Security pages (all) | **Changed** — nav group only |

No resource was rebuilt or had its Schema/Table/Page structure altered beyond the specific fixes above.

## Tests

- `tests/Feature/Filament/ActivityLoggingCoverageTest.php` — new: confirms `LogsActivity` actually produces an `activity_log` row for each of the 8 newly-instrumented models, not just that the trait is present.
- `tests/Feature/Roles/RoleReplicateActionTest.php` — new: a manager with `Replicate:Role` but not `AssignPermissions:Role` gets an empty-permission replica; a manager with both gets the full copy.
- Full suite re-run after every change group; no existing test needed modification (navigation group and global search changes are additive/cosmetic to test behavior).
