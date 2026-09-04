# Admin Forms — Shared Presentation Conventions

The shared presentation conventions for Filament admin forms/pages: headings, subheadings, breadcrumbs, Back navigation, action labels, and semantic color. Each convention below is either (a) a rule to apply when touching a page, or (b) a small, tested, reusable helper already built. Not every existing page has adopted every convention yet — apply the relevant trait/helper below when you next touch a page, rather than doing a one-time sweep.

For the functional/security findings found alongside this work but deliberately out of scope for presentation changes, see the open backlog: [docs/audits/admin-forms-remediation-backlog.md](../audits/admin-forms-remediation-backlog.md). The original audit that surfaced the findings these conventions respond to is retained for historical context at [`docs/archive/audits/admin-forms-stage1-audit.md`](../archive/audits/admin-forms-stage1-audit.md) — not required reading to apply anything below.

## 1. Full-width content

`AdminPanelProvider::panel()` already sets `->maxContentWidth(Width::Full)` panel-wide. This was verified against the framework's public `Panel::getMaxContentWidth()` accessor (`tests/Unit/Filament/AdminPanelPresentationConfigurationTest.php`) and applies to every resource and page in the panel today — no page overrides it.

**Convention:** do not add a page-level `maxContentWidth()` override. If a specific page is later found not to inherit the panel setting, that's a bug to fix at the panel level, not a reason to add a per-page override.

## 2. Responsive layout convention

- **Mobile:** one column.
- **Tablet:** one or two columns, based on how closely related the fields are.
- **Desktop:** up to two columns for ordinary fields.
- **Three columns** only for short, closely related values (e.g. a code/name/order triplet).
- **Full grid span** for: `Textarea`, rich editors, repeaters, block builders, file uploads, and complex relationship pickers (multi-select with search, etc.).
- Sections span the available page width (no fixed-width containers).
- No horizontal page scrolling under any breakpoint.

Filament's own `Grid::make(int $columns)` already applies only at the `lg` breakpoint and stacks to one column below it (confirmed in `vendor/filament/schemas/src/Concerns/HasColumns.php`) — this convention is about *which* column count each field group should target, not a new mechanism. Apply it schema by schema as each page is touched.

## 3. "Create & create another" — removed centrally

**Extension point used:** `Filament\Resources\Pages\CreateRecord` exposes a public static `disableCreateAnother()` method that flips its own `protected static bool $canCreateAnother` property. Every Create page in the app extends `CreateRecord` and none of them redeclares that property or overrides `canCreateAnother()` (verified — see below), so calling `disableCreateAnother()` once on the base class turns it off for every subclass through ordinary late static binding. No macro, no CSS, no JS, no vendor file, no per-page edit.

**Where:** `App\Providers\Filament\AdminPanelProvider::boot()`:

```php
public function boot(): void
{
    CreateRecord::disableCreateAnother();
}
```

**Opt-in for a genuine repeated-entry workflow:** a Create page that needs it back declares `protected static bool $canCreateAnother = true;` on itself — the same framework mechanism, used in the other direction. No such page exists today.

**Why this is safe:**
- `Create` and `Cancel` are untouched — only the middle action is removed from `getFormActions()`.
- Record creation, redirects, notifications, hooks (`afterCreate` etc.), and authorization are all untouched — `disableCreateAnother()` only affects which buttons render.
- Verified with a repo-wide reflection scan (`tests/Feature/Filament/CreateAnotherActionRemovedTest.php::test_no_create_page_overrides_the_disabled_default`) that no `Create*` page under `app/Filament/Resources/**/Pages/` redeclares the property or the method, plus rendered-output assertions on two representative pages (`CreateReviewTag`, `CreateFaqCategory`) proving the button is actually gone, `Create` still works end-to-end, and validation is unchanged.

## 4. Shared presentation contract — extension points

| Concern | Framework extension point | Status |
|---|---|---|
| Page heading | `getHeading()` / protected `$heading` property (`Filament\Pages\BasePage`); resource pages also have `getTitle()` | Native, no wrapper needed |
| Subheading | `getSubheading()` / protected `$subheading` property (`Filament\Pages\BasePage`) | Native, no wrapper needed |
| Breadcrumbs | `getBreadcrumbs(): array` (overridable on both `Filament\Pages\Page` and `Filament\Resources\Pages\Page`) | New: `App\Filament\Navigation\BreadcrumbResolver` + two thin traits |
| Back destination | No native "Back" concept exists — modeled as a plain `Filament\Actions\Action` | New: `App\Filament\Support\Presentation\BackAction` factory |
| Primary submit label | `getCreateFormAction()` / `getSaveFormAction()` (override per page if ever needed) | Native defaults already match §6 below |
| Secondary Cancel action | `getCancelFormAction()` | Native, already correct everywhere |
| Semantic action color | `->color()` on any `Filament\Actions\Action` | Native, see §7 |

Deliberately **not** built: a single mega-trait combining all six concerns. Headings/subheadings/submit-labels/cancel/colors already have clean native override points that don't need wrapping — adding a trait around them would just be indirection. Only breadcrumbs and Back needed new shared code, because Filament's own defaults for those two are either inconsistent across the two page hierarchies (breadcrumbs) or don't exist at all (Back).

## 5. Heading standards

**Resource create pages:** `Create {singular resource label}` — this is Filament's own default (`getTitle()` on `CreateRecord`), unchanged.

**Resource edit pages:** `Edit {record title}`, falling back to `Edit {singular resource label}` if no safe record title is available — this is Filament's own default (`getTitle()` on `EditRecord`, via `getRecordTitle()`). Never expose a sensitive record value (an email, a bank detail, a raw ID) in a heading; if a resource's natural "title" field is sensitive, that resource must override `getRecordTitle()`/`getTitle()` to use a safe label instead.

**Settings pages:** the configuration domain name (e.g. "Payment Configuration", "Instructor Earnings Rules", "Review & Quality Configuration") — never prefixed with "Create"/"Edit". Several settings pages already do this correctly (custom `getTitle()`); this is documentation of the existing correct pattern, not a new mechanism.

**Section headings:** never repeat the page heading. Use concise, generic labels — "Details", "Category details", "Visibility", "Publishing", "Access", "Configuration" — chosen per form as each one is touched, not applied panel-wide here.

## 6. Subheading standards

Subheadings are optional. Add one only when it explains what the record controls, where it appears, a meaningful consequence of saving, a prerequisite, or a non-obvious limitation. Several settings pages already do this well (e.g. `InstructorEarningSettingsPage`: *"Payout rules: how instructor earnings are calculated, held, released, and settled. No external transfers are executed."*) — that's the bar to match.

**Avoid:** "Fill out the form below," "Manage your settings," repeating the heading, long implementation explanations, or any phase/development-history language.

**Reusable mechanism:** none needed — `getSubheading()`/the protected `$subheading` property already accept a plain string, so "without embedding HTML" is satisfied natively. A page only needs to set `protected ?string $subheading = '...';` or override `getSubheading(): ?string`.

## 7. Breadcrumb standards

**Target shapes:**
- Create: `Section → Resource → Create`
- Edit: `Section → Resource → Record → Edit`
- Settings: `Settings → Subgroup → Page`

**What exists today, and why a new resolver was needed:** `Filament\Resources\Pages\Page::getBreadcrumbs()` already builds a correct `Resource → Create/Edit` trail (with real links) — it just never includes a leading Section. `Filament\Pages\Page::getBreadcrumbs()` (used by every standalone Settings/Security page) returns `[]` by default — some settings pages hand-rolled their own trail, inconsistently, and one hardcodes the same wrong mid-crumb on every page in the group.

**New shared code** (`app/Filament/Navigation/`):
- `BreadcrumbResolver::prependSection(array $trail, ?string $sectionLabel)` — prepends a plain-text Section crumb in front of an existing resource breadcrumb trail. Returns the trail unchanged if no section is known.
- `BreadcrumbResolver::forSettingsPage(?string $section, ?string $subgroup, ?string $currentLabel)` — builds the full `Settings → Subgroup → Page` trail for standalone pages, dropping any missing segment instead of rendering it blank.
- `Concerns\HasSectionBreadcrumb` — one-line trait for Resource pages: `BreadcrumbResolver::prependSection(parent::getBreadcrumbs(), NavigationRegistry::groupFor(static::getResource()))`.
- `Concerns\HasSettingsSectionBreadcrumb` — one-line trait for standalone pages: reads `NavigationRegistry::groupFor()`/`subgroupFor()` (the latter a new additive accessor alongside the existing `groupFor()`/`labelFor()`/`sortFor()`) plus the page's own `getHeading()`.

Both trails are built entirely from **plain-text** crumbs where no destination page exists to link to (Filament renders an int-keyed breadcrumb entry as text, a string-keyed one as a link — see `vendor/filament/support/resources/views/components/breadcrumbs.blade.php`). No new route or landing page is invented. The current page is always the last, non-linking entry. Existing linked entries (List, parent record) from Filament's own resource trail are preserved untouched.

**Legacy URLs:** untouched — breadcrumbs are display-only and never change a route.

**Fully unit-tested** (`tests/Unit/Filament/BreadcrumbResolverTest.php`, `tests/Unit/Filament/AdminNavigationRegistryTest.php::test_subgroup_for_matches_the_registry_entry`). The two traits are pure, page-independent logic — see §9 below for why adopting them onto a specific page is a separate, later step rather than something this document does itself.

## 8. Back navigation standards

**New shared code:** `App\Filament\Support\Presentation\BackAction::make(?string $url, string $label = 'Back', string $key = 'back'): ?Action` — returns `null` when no destination is known (so a page can omit Back entirely rather than link somewhere unauthorized), otherwise returns a `gray`-colored `Action` with the same `heroicon-o-arrow-left` icon already hand-written on `ActivityLog`/`LoginHistory`'s View pages today. This factory codifies that existing pattern instead of inventing a new one.

**Rules for later adoption:**
- Create/Edit pages: Back returns to the authorized resource index.
- Nested resources: Back returns to the parent context.
- Settings child pages: Back returns to that settings group's own destination — never hardcoded to General.
- No `window.history.back()` — the URL is always a known, authorized destination.
- Hidden (via the factory's `null` return) when no safe destination exists.
- **Back vs. Cancel:** Back is a page-header action (next to the heading); Cancel stays a form action (next to Create/Save). They may point at the same URL, but serve different purposes — Back is "leave this page," Cancel is "abandon this form input." A page that already has an equivalent control (e.g. `ActivityLog`/`LoginHistory`'s hand-written Back action) should be migrated to the factory in its own domain batch, not have a second Back added alongside it.
- Not added to modal forms.

**Tested:** `tests/Unit/Filament/BackActionTest.php` — null-when-unknown, label/icon/color/url wiring, custom key.

## 9. Adopting the breadcrumb/back mechanisms on a page

Both `HasSectionBreadcrumb`/`HasSettingsSectionBreadcrumb` and `BackAction` read from `NavigationRegistry`/act on a real page's own class identity — attaching either to a page is a one- or two-line change (add the trait, or call the factory) rather than something that needs re-deriving per page. Adopt them the next time you touch a page that doesn't have them yet, rather than as a separate blanket migration.

## 10. Action label standards

Already the framework default, confirmed against the installed package's language files (`vendor/filament/filament/resources/lang/en/resources/pages/{create,edit}-record.php`) — no change made:

- **Resource create:** primary `Create`, secondary `Cancel`.
- **Resource edit:** primary `Save changes`, secondary `Cancel`. Destructive actions (Delete, etc.) stay separate header actions, never a form action.
- **Settings:** primary `Save changes` (several existing pages already use a more specific variant like "Save Mail Settings" — that's fine per-page specificity, not a violation).

**Named exception, kept as-is:** `AdminChangePassword`'s `Update Password` label is clearer and more accurate for that specialized operation than a generic `Save changes` would be — do not force it to the generic wording.

No action execution, hooks, notifications, or redirects are affected by any labeling decision.

## 11. Semantic color standards

Documented here; not yet re-applied to every existing lifecycle action across the panel (see the remediation backlog for the specific actions still colored inconsistently):

- **Primary submission** → `primary`
- **Secondary navigation / cancellation** → `gray` (neutral)
- **Informational action** → `info`
- **Positive state transition** → `success`
- **Caution / reversible suspension** → `warning`
- **Destructive or irreversible action** → `danger`

Always via Filament's semantic `->color()` API (or an enum's own `->color()` method) — never a hardcoded hex/RGB value or a raw Tailwind palette class in a form component. The Navigation Menu Builder's existing hardcoded panel colors are a known exception, tracked in the remediation backlog as its own custom-component task, not touched here.


## List tables (index pages)

Every resource table ends with `AdminListTable::apply($table, 'Search …')`
(`app/Filament/Support/Tables/AdminListTable.php`). That gives each list:

- every filter as its own always-visible control above the table (no filter dropdown), four per row on large screens
- filters applied instantly and remembered for the session, as is the search box
- striped rows, 25 / 50 / 100 per page

On top of that, per resource:

- **Tabs.** List pages of models with a status enum expose `getTabs()` via
  `StatusTabs::forEnum(Model::class, StatusEnum::class)` (All + one tab per case with
  live counts; uses the enum's `label()` / `color()` when defined). Academic catalogue models use
  `AcademicStatusTabs::make()` which adds a Deleted tab and pairs with
  `AcademicStatusTabs::activeToggleColumn()` and `bulkStatusActions()`.
- **Inline toggles.** Boolean flags an admin may change directly (`is_active`, `featured`,
  `required`…) render as `ToggleColumn`, disabled when the admin lacks `update` on the row.
  Read-only booleans (audit or provider facts such as `requires_fraud_review`) stay as `IconColumn`.
- **Record titles.** Never let a UUID reach a breadcrumb or heading: give the resource a
  `$recordTitleAttribute` or override `getRecordTitle()` with a readable summary of the row.
- **Groups.** Where a list has an obvious parent (subject, category, instructor), offer it under
  `->groups([...])` so an admin can review a whole slice at once.
