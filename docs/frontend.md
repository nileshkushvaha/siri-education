# Public Frontend

Foundation for Blade + Livewire + Alpine + Tailwind frontend work.
Extends the existing frontend infrastructure (layouts, Blade components
under `resources/views/components/`) rather than duplicating it; see
"What already existed" below before assuming something needs building.

## Stack

- Blade (server-rendered views, `@extends`/`@section`/`x-` components)
- **Livewire 4.3.3** — installed transitively via Filament 5.6.8
  (`filament/support` requires `livewire/livewire ^4.1`).
  **Not literal Livewire 3** — that version conflicts with the
  installed Filament and would require downgrading the entire admin
  panel. Livewire 4 is API-compatible with the v3 patterns referenced
  in this doc (`wire:model`, lifecycle hooks, `#[Layout]`/`#[Title]`
  attributes, computed properties).
- Alpine.js — **provided by Livewire**, not separately loaded. Livewire
  4 bundles and auto-starts its own Alpine instance as part of
  `@livewireScripts`. A standalone CDN/npm Alpine was originally
  loaded alongside it here and caused "Detected multiple instances of
  Alpine running" plus Livewire-internal breakage (`Alpine.transaction
  is not a function`) — **never add a second Alpine script/import**.
  Plugins (e.g. `@alpinejs/collapse`, used by the accordion/FAQ/mobile-nav
  components) are npm-installed and registered against Livewire's
  instance via `Alpine.plugin(...)` inside the `alpine:init` listener
  in `resources/js/frontend/alpine.js` — add future plugins the same
  way. Existing inline `x-data="{...}"` usage is unaffected; Livewire's
  Alpine is a full, real Alpine instance with the same API.
- Tailwind CSS 4.3.1 with `@tailwindcss/vite` 4.3.1 (no
  `tailwind.config.js` — design tokens live in CSS `@theme` blocks)
- Vite 8.1.0 with `laravel-vite-plugin` 3.1.0

## Layouts

| Layout | Extends | Audience | Status |
|---|---|---|---|
| `layouts.frontend` | — (base HTML shell) | all | SEO/analytics/Vite/Alpine/Livewire assets, header, footer, flash messages |
| `layouts.guest` | `layouts.frontend` | anonymous visitors | thin, intention-named entry point for public pages |
| `layouts.auth` | `layouts.frontend` (bare) | sign-in/up style pages | reusable split-panel chrome using the `.auth-*` CSS classes |
| `layouts.student` | `layouts.account` | authenticated frontend-portal users | thin alias; `layouts.account` implements the dark portal chrome used across the dashboard |
| `layouts.error` | `layouts.frontend` | any (error pages) | reusable dark-hero chrome, shared by `errors/404.blade.php` / `errors/500.blade.php` |

`layouts.account`, `layouts.page`, `layouts.landing`, `layouts.blank`, and every file under `resources/views/auth/` and `resources/views/errors/` are separate, established layouts — extend the one matching your page's audience by name rather than building a new one.

The one shared edit: `layouts/frontend.blade.php` gained
`@livewireStyles` (head) and `@livewireScripts` (before `</body>`) —
required once, application-wide, for any future Livewire component to
render correctly, regardless of which layout it's used in.

### Why thin wrappers instead of new implementations

`layouts.guest` and `layouts.student` add no markup of their own —
they exist so new work has a stable, audience-named layout to target
without coupling to (or duplicating) the underlying shell/portal
implementation. This is the same pattern Laravel's own starter kits
use (`layouts/guest.blade.php` alongside `layouts/app.blade.php`).

## Shared Blade components

`resources/views/components/ui/` — new generic, presentation-only
primitives for the frontend build-out (existing component folders are
all domain-specific: `booking/`, `account/`, `blocks/`, etc., with no
generic layer):

| Component | Purpose |
|---|---|
| `<x-ui.accordion>` | accessible disclosure list; supports single or multiple open panels |
| `<x-ui.alert>` | generic inline banner (`success`\|`error`\|`warning`\|`info`), `role="alert"` |
| `<x-ui.avatar>` | image or initials avatar with size variants |
| `<x-ui.badge>` | small status pill, semantic colors |
| `<x-ui.breadcrumb>` | accessible breadcrumb navigation from item arrays or slot content |
| `<x-ui.button>` | variant (`primary`\|`secondary`\|`ghost`\|`danger`) + size; renders `<a>` when `href` is passed |
| `<x-ui.card>` | generic bordered/padded container |
| `<x-ui.checkbox>` | labeled checkbox with hint/disabled support |
| `<x-ui.container>` | centralizes the `max-w-7xl mx-auto px-4 sm:px-6 lg:px-8` pattern repeated ad hoc across existing views |
| `<x-ui.drawer>` | Alpine-powered off-canvas panel, opened by `open-drawer` events |
| `<x-ui.dropdown>` | Alpine-powered menu wrapper with trigger slot |
| `<x-ui.empty-state>` | reusable empty-result panel with icon/action slots |
| `<x-ui.input>` | labeled input with hint, validation error, and dark-mode states |
| `<x-ui.modal>` | Alpine-powered accessible dialog, opened by `open-modal` events |
| `<x-ui.pagination>` | styled pagination controls for Laravel paginator instances |
| `<x-ui.radio>` | labeled radio input with hint/disabled support |
| `<x-ui.select>` | labeled select with options/slot, hint, and validation error |
| `<x-ui.skeleton>` | block/text/avatar loading placeholders |
| `<x-ui.spinner>` | accessible loading spinner |
| `<x-ui.tabs>` | accessible tablist from item arrays plus optional custom slot content |
| `<x-ui.toggle>` | accessible switch-style checkbox |
| `<x-ui.tooltip>` | hover/focus tooltip wrapper |

All follow the existing convention (see `x-booking.option-card`):
`$attributes->merge()` for flexible styling, real semantic elements,
`focus-visible` rings, minimal local state only, and dark-mode-ready
Tailwind classes. Components remain presentation-only; any data they
display must come from controllers, Livewire components, Services, or
Repositories according to the enterprise architecture.

## Shared Livewire components

`app/Livewire/Frontend/` (+ matching views at
`resources/views/livewire/frontend/`) — namespace for new
public/student-facing components, parallel to the existing
`App\Livewire\Navigation\*` (admin nav builder).

- No business logic in a component — it may only call an existing
  Service/Action/Repository and hand the result to the view.
- Business-rule validation and authorization go through the same
  Policies/Services everything else uses; component-level `rules()`
  is for input *shape* only.
- Naming: `App\Livewire\Frontend\{Feature}\{Name}` →
  `resources/views/livewire/frontend/{feature}/{name}.blade.php`
  (Livewire's default discovery convention — no manual registration).

### Public website layout components

`App\Livewire\Frontend\Layout\*` powers the shared website chrome:

| Component | Responsibility | Data source |
|---|---|---|
| `SiteHeader` | Sticky header, desktop navigation, mega menu, mobile navigation, auth/search actions | `NavigationManager`, `PortalResolver` |
| `SiteFooter` | Footer navigation, contact details, latest posts | `NavigationManager`, `PostService` |
| `SearchOverlay` | Interactive search overlay for published pages/posts | `PageService`, `PostService` |
| `CookieBanner` | Cookie notice visibility and consent cookie | request cookie / Laravel cookie queue |
| `AnnouncementBar` | Optional deploy-time announcement | `config/frontend.php` |

The header uses CMS navigation location `header`; mobile navigation
uses location `mobile` with a header-menu fallback. The footer uses
location `footer`. These components are layout chrome only; they do
not create pages and do not decide portal membership or authorization.

## CMS page rendering

Public CMS pages render through the existing CMS pipeline:

```
PageController
→ PageService::getPublishedPage()
→ PageRenderService::render()
→ BlockRenderer::render()
→ resources/views/components/blocks/{type}.blade.php
```

Reusable block views exist for Hero, CTA, FAQ, Features,
Featured Courses, Featured Teachers, Testimonials, Gallery, Pricing,
Newsletter, Contact, Rich Content, and the other registered
`BlockType` cases. The frontend must not hardcode block content or
create page-specific render paths; new page sections should be added
as reusable `BlockType` definitions with a form schema, converter,
hydrator, renderer mapping, and Blade block view.

The root homepage renders CMS content first: the configured static
page wins, then a published CMS page with slug `home`. The legacy
template remains only as a compatibility fallback for installations
that have not created CMS homepage content yet.

## Asset structure

```
resources/css/
├── app.css                    existing master stylesheet (untouched, 500+ lines)
├── frontend/
│   └── theme.css              NEW — design tokens for frontend work, @imported into app.css
└── filament/admin/theme.css   existing — Filament admin theme (separate, untouched)

resources/js/
├── app.js                     existing entry — now imports ./frontend/alpine
└── frontend/
    └── alpine.js              NEW — extension point for Alpine.data()/directive() registrations
```

`theme.css` is `@import`ed into `app.css`, not registered as a
separate Vite entry — Tailwind v4 merges `@theme` blocks across
imported files, so this only adds tokens, it doesn't replace anything.
`vite.config.js` is unchanged (no new entry needed).

## Theme configuration

Two layers, matching how the rest of the app already separates
"structural config" from "editable settings":

- **`resources/css/frontend/theme.css`** — Tailwind `@theme` design
  tokens (`--color-brand-*`, `--color-surface-*`, `--radius-card`,
  `--radius-pill`) capturing values already repeated as one-off
  literals across existing components, for new components to
  reference by name.
- **`config/frontend.php`** — deploy-time constants (`default_og_image`,
  responsive `breakpoints` mirroring Tailwind's scale). Anything
  admin-editable belongs in a Spatie Settings class instead (see
  `GeneralSettings`/`SeoSettings`, already consumed by
  `layouts.frontend`) — this config file is only for values that
  aren't.

## Frontend service provider

`App\Providers\FrontendServiceProvider` — registered in
`bootstrap/providers.php`, following the app's established
one-provider-per-module convention (`BookingServiceProvider`,
`NavigationServiceProvider`, `CmsServiceProvider`).

Its one responsibility: the `AccountPortalComposer` view-composer
registration, **moved here from `AppServiceProvider`** (previously the
only frontend-specific registration mixed into that provider). Config
and Blade/Livewire component discovery need no registration — Laravel
and Livewire handle both by convention — so there was nothing else
legitimate to put in this provider; it was kept intentionally minimal
rather than given speculative responsibilities.

## Portal architecture (unchanged)

`PortalResolver` remains the single source of truth for portal
routing. `layouts.student` sits *below*
`PortalResolver` in the stack: it's a rendering choice for pages the
resolver has already decided belong to the Frontend Portal, not a
routing decision itself.

## Authentication (Livewire)

Five pages — Login, Register, Forgot Password, Reset Password, Email
Verification — got Livewire-powered forms in place of their classic
full-page POST submissions, reusing 100% of the existing auth backend
(`LoginService`, `RegistrationService`, `PasswordResetService`,
`PasswordRuleBuilder`, the `LoginRequest`/`RegisterRequest`/etc.
FormRequests). **The classic controllers and POST routes
(`auth.login.store`, `auth.register.store`, `auth.password.email`,
`auth.password.update`) are untouched** — an extensive existing
security test suite (`tests/Feature/Security/LoginSecurityTest.php`
and others) exercises them directly, so they stay in place as the
non-JS fallback rather than being replaced.

| Page | Component | View |
|---|---|---|
| Login | `App\Livewire\Frontend\Auth\LoginForm` | `livewire.frontend.auth.login-form` |
| Register | `RegisterForm` | `register-form` |
| Forgot password | `ForgotPasswordForm` | `forgot-password-form` |
| Reset password | `ResetPasswordForm` | `reset-password-form` |
| Email verification (code entry + resend) | `VerifyEmailNotice` | `verify-email-notice` |

Each existing page under `resources/views/auth/` keeps its decorative
chrome (left panel, testimonials, copy) — only the `<form>` element
was replaced with the matching `<livewire:frontend.auth.*>` tag, and
dead Alpine state that only the old form used (`x-data="registerForm()"`
and its `@push('scripts')` block, the old cooldown timer, etc.) was
removed rather than left dangling.

### Validation — zero duplicated rules

Each component's `rules()`/`messages()`/`validationAttributes()`
return `(new XxxRequest)->rules()` etc. directly — the exact same
FormRequest classes the classic controllers use. One gotcha: a
classic HTTP request passes through Laravel's global
`ConvertEmptyStringsToNull` middleware before validation, which is
why `RegisterRequest`'s `'nullable'` phone rule tolerates an empty
field; Livewire's `$this->validate()` validates component properties
directly with no such middleware, so `RegisterForm::register()`
normalizes blank optional fields to `null` itself before validating,
rather than relaxing the shared rule.

### Rate limiting

Livewire action calls (e.g. clicking "Sign in") are dispatched to
Livewire's own internal update endpoint, not the page's route — so
the existing `throttle:login` / `throttle:password.reset` **route**
middleware never runs for them. `App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests`
resolves the same named limiters already registered once in
`AppServiceProvider::registerRateLimiters()` and applies them
manually (`RateLimiter::tooManyAttempts()`/`hit()`), so the threshold
and its settings-driven on/off toggle
(`LoginSecuritySettings::throttling_enabled`) are still defined in
exactly one place. `VerifyEmailNotice`'s code resend uses a simple
inline numeric throttle mirroring the small closure/route-level throttle
it replaces (6/min) — not a named limiter, so the trait doesn't apply
there.

### Remember me

`LoginForm::$remember` (bound via `<x-ui.auth-checkbox wire:model="remember">`,
shown only when `AuthenticationSettings::remember_me_enabled` is on)
passes straight through to `LoginService::attempt(remember: ...)` —
identical to what `LoginController::store()` already did.

### Reusable form components

`resources/views/components/ui/auth-input.blade.php`,
`auth-checkbox.blade.php`, `auth-button.blade.php` — wrap the
`.auth-input`/`.auth-label`/`.auth-card`/`.auth-btn-primary` CSS
classes already defined in `resources/css/app.css` (not the
generic `<x-ui.input>` kit, which is styled for a light theme; the
auth pages are dark-themed by design via these bespoke classes).
These replace what was previously hand-rolled 8+ times across the
five static pages. `auth-checkbox` accepts either a plain-text
`label` prop (escaped) or rich content via its default slot (rendered
raw — only ever developer-authored Blade, e.g. the terms-of-service
checkbox's embedded links, never user input).

### Testing

`tests/Feature/Auth/{LoginFormTest,RegisterFormTest,ForgotPasswordFormTest,ResetPasswordFormTest,VerifyEmailNoticeTest}.php`
— 23 tests using `Livewire::test()`, covering validation, the named
rate limiter engaging after repeated failures, remember-me
persistence, the unverified-email banner + resend, admin-portal
redirect-instead-of-authenticate, and the full reset-password token
flow.

## Student Dashboard (Livewire)

Every dashboard section is an independent, embedded Livewire
component under `App\Livewire\Frontend\Student\*`, rendered inside a
classic (non-full-page) Blade view extending `layouts.account`. Data
comes from existing services — no new read paths were invented:

| Section | Component | Data source |
|---|---|---|
| Dashboard | `DashboardOverview` | `StudentBookingServiceInterface` + `HomeworkServiceInterface` |
| Upcoming Classes | `UpcomingClasses` | `StudentBookingServiceInterface::upcomingClasses()` |
| Bookings | `BookingHistory` | `StudentBookingServiceInterface::bookingHistory()` (paginated, status filter) |
| Payments | `PaymentHistory` | `StudentBookingServiceInterface::paymentHistory()` (paginated) |
| Homework | `HomeworkList` | `HomeworkServiceInterface` (paginated + submit action) |
| Attendance | `AttendanceHistory` | `StudentBookingServiceInterface::attendanceStats()/attendanceHistory()` |
| Progress | `ProgressOverview` | `StudentBookingServiceInterface::progressStats()/subjectBreakdown()` |
| Notifications | `NotificationsPanel` | `auth()->user()->notifications()` (same as the pre-existing controller) |
| Profile / Settings | *(reused, not rebuilt)* | `resources/views/profile/show.blade.php` + `ProfileController` |

### Attendance & Progress are derived, not new tables

Rather than add redundant `attendances`/`progress` tables, both
sections read the existing `bookings` table: `status = completed` is
"attended", `status = no_show` is "missed", and session hours/subject
breakdown are computed from `starts_at`/`ends_at`/`meta->subject` —
the same fields `BookingAnalyticsRepository` already aggregates for
admin reporting. See the six read methods added to
`BookingRepositoryInterface` (`paginatedForUser`,
`paginatedPaymentsForUser`, `attendanceStatsForUser`,
`attendanceHistoryForUser`, `progressStatsForUser`,
`subjectBreakdownForUser`) and their thin pass-through wrappers on
`StudentBookingServiceInterface`.

### Homework domain

New minimal domain under `App\Homework\*` (migrations, model,
repository, service, policy, provider) mirroring the `App\Booking\*`
structure. `HomeworkAssignment` belongs to a student, a teacher, and
optionally a `Booking`. `HomeworkAssignmentPolicy::submit()` restricts
submission to the assignment's own student; `SubmitHomeworkAction`
guards against resubmitting non-pending work.

### Profile & Settings — reused, not rewritten

`resources/views/profile/show.blade.php` (Alpine-tab based: General /
Security / Notifications) plus `ProfileController` already implement
both "Profile" and "Settings" with real backend
(`ProfileService`, `SessionService`, `ProfileCompletionService`).
Rebuilding ~800 lines of working, tab-based UI into Livewire for the
sake of literal section-count compliance would have been a high-risk,
zero-benefit rewrite — the sidebar's "Settings" entry instead links to
`profile.show?tab=security` (the tab-init script now reads
`request('tab', session('active_tab', 'general'))` so it can be
deep-linked, falling back to the existing session-flash behavior).

### Testing

`tests/Feature/Student/{DashboardOverviewTest,UpcomingClassesTest,BookingHistoryTest,PaymentHistoryTest,HomeworkListTest,AttendanceHistoryTest,ProgressOverviewTest,NotificationsPanelTest}.php`
— 75 tests using `Livewire::test()`/`Livewire::actingAs()`, covering
per-student data isolation, pagination, status filtering, the
homework submit authorization + duplicate-submission guard, and
empty states.
