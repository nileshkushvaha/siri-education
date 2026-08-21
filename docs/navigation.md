# Navigation

## Overview

The navigation system is a standalone bounded context in `app/Navigation/`. It is registered by `NavigationServiceProvider`.

## Key classes

| Class | Purpose |
|---|---|
| `NavigationManager` | Top-level orchestrator, resolves menus by location |
| `NavigationRepository` | Eloquent queries, eager-loads items + roles + permissions |
| `NavigationRenderer` | Converts DB tree to HTML via Blade components |
| `NavigationCacheManager` | Invalidates/warms cache (tagged: `navigation`) |
| `NavigationItemService` | Resolves `is_active` state for current URL |
| `PermissionEvaluator` | Visibility checks (roles, permissions, publish windows) |
| `UrlResolver` + `Drivers/` | link type drivers, registered in `NavigationServiceProvider` |

## Models

`App\Models\NavigationMenu` — menu container (name, location slug).

### Locations

`App\Enums\Navigation\NavigationLocation` — `header`, `footer`, `footer_learning`, `mobile`, `sidebar`, `user_menu`, `custom`.

`footer_learning` drives the **Learning** column of the public footer
(`App\Livewire\Frontend\Layout\SiteFooter::learningNavigation()`). When no menu
is published there the column falls back to the latest blog posts, then to a
static link list — so leaving it empty changes nothing.

It replaced the former `admin_menu` location, which had no renderer and no
reader: the admin panel's navigation is Filament's own, not a `NavigationMenu`.
Migration `2026_08_20_170000_rename_admin_menu_navigation_location_to_footer_learning`
repoints existing rows rather than deleting them.

**One published menu per location.** `NavigationRepository::findByLocation()`
resolves with an unordered `->first()`, so two published menus at one location
make the rendered menu non-deterministic. `NavigationSeeder` therefore skips any
location that already has a menu, whatever it is named.

`App\Models\NavigationItem` — uses **Kalnoy NestedSet** (`_lft`, `_rgt`, `parent_id`, `depth`). Never use raw adjacency list queries.

## Link types (`App\Enums\Navigation\NavigationLinkType`)

11 cases: Page, Post, Category, Tag, Route (named route), Url, External, Email, Phone, Anchor, Custom.

10 of these have a registered driver in `app/Navigation/Drivers/`, wired in `NavigationServiceProvider`. `Custom` has no driver registered — `LinkTypeRegistry::resolve()` throws if a type has no driver, so confirm how `Custom` items are actually resolved (likely a direct stored URL, not driver-resolved) before adding a new link type that assumes every case goes through a driver.

## Item visibility

- Role-based: `NavigationItemRoles` pivot — item shown only to listed roles
- Permission-based: `NavigationItemPermissions` pivot — item shown only to users with listed permissions
- Publish windows: `published_at` / `expired_at` on items
- `PermissionEvaluator` checks all three before rendering

## Cache

Navigation trees are cached with tag `navigation`. Any mutation to `NavigationMenu` or `NavigationItem` (via observer) invalidates the cache. `NavigationCacheManager::warm()` pre-builds the cache.

## Admin

`app/Livewire/Navigation/MenuBuilder.php` — Livewire component for drag-and-drop tree editing.

Filament Resource: `app/Filament/Resources/NavigationMenus/` — CMS group.
