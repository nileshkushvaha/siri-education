# Admin Dashboard

The `/admin` landing page: a marketplace command centre that answers, in
order, *what needs attention right now*, *how did the marketplace perform
this period*, and *where should I go next*.

It renders **no data tables**. The registration / login / audit-trail
tables it replaced were identity-system activity, not marketplace
management; each remains one click away in the module that owns it.

## The one rule that shapes everything

**The dashboard is not a calculation owner.** Every figure comes from an
existing `App\Reporting` service (or `BookingAnalyticsService`, the owner
the `MetricRegistry` names for booking trend and demo-to-paid
conversion). The dashboard re-shapes those results for display and links
into the report that owns them. It never defines a metric, never varies
an existing one, and never invents a metric the registry does not
define — the same discipline
`App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData` states for the
executive overview.

Consequently the metrics the registry formally refuses — revenue,
retention, instructor utilization, meeting reliability, referral
conversion, in-app notification delivery rate, search/profile-view
analytics, historical wallet liability, cross-currency totals — are
absent here too, and must stay absent.

## Layout

| Order | Section | Owner |
|---|---|---|
| 0 | Global context (period, timezone, country) + freshness | `Dashboard` page |
| 1 | **Needs attention** — current-state exceptions | `AttentionFeedService` |
| 2 | Primary KPIs (max 6) | `DashboardCompositionService` |
| 3 | Core charts (max 4, + 1 in the learning section) | `App\Filament\Widgets\Dashboard\*` |
| 4 | Domain summaries — marketplace, learning, quality, money | `DashboardCompositionService` |
| 5 | Report launchpad | `ReportRegistryInterface::availableFor()` |
| 6 | Administration (secondary) | `DashboardCompositionService` |
| 7 | System health (super-admin, subordinate) | `SystemHealthReader` |

Caps: 6 attention cards visible, 6 primary KPIs, 5 charts, 6 primary
report links, 0 tables.

## Module map — `app/Dashboard/`

| File | Responsibility |
|---|---|
| `Services/DashboardPermissions.php` | The single permission resolver. Wraps both mechanisms in this codebase: report permissions via `ReportAccessContextInterface` (which closes the Spatie/`Gate::before` gap itself) and everything else via `Gate`. Also produces the permission `signature()` used in cache keys. |
| `Services/DashboardCompositionService.php` | Assembles the period-scoped dashboard. Checks permission **before** calling each owning service; calls each at most once. |
| `Services/AttentionFeedService.php` | Builds the current-state exception cards. |
| `Services/ProviderActivationReader.php` | Honest real-money provider state. Reads `*_enabled` + `*_config_status`; never a credential. |
| `Services/SystemHealthReader.php` | Super-admin system strip. |
| `Support/DashboardUrl.php` | Every destination. Resource indexes (`?filters[...]`, `?tab=`) and report pages (`?period=`, `?country=`, `?section=`). Invents no route. |
| `DTOs/*` | Readonly view models. A section absent from the DTO was never queried. |

## Permission behaviour

An unauthorised section is **omitted before querying**, not fetched and
hidden. There are no "Restricted" placeholders; the grid closes the gap.
`tests/Feature/Dashboard/DashboardAccessTest.php` asserts this by
inspecting the query log, not the rendered output.

Two separations are load-bearing:

- `ViewFinanceReports` never implies `ViewInstructorCompensationReports`.
  Instructor earning liability and withdrawal counts require the latter
  (plus the former, since `ReportCategory::EarningsSettlements` requires
  finance — both are checked).
- `bookingSummary()` / `lessonOutcomeSummary()` need
  `ViewOperationalReports` **and** `ViewBookingLessonReports`. Use
  `canViewOperationsSummaries()`; `canViewOperations()` is the weaker
  gate for the repository-level current-state metrics.

Suspicious-activity flags are surfaced on the dashboard to super
administrators only, deliberately stricter than the resource permission.

## Freshness and caching

Two independent policies, never merged, both declared honestly as
`ReportDataFreshness::CachedWithTimestamp` — neither claims `Live`:

| Section | TTL | Why |
|---|---|---|
| Period composition | 300s | Mirrors `BookingAnalyticsService`'s existing policy. |
| Needs attention | 60s | Urgent counts must not be materially stale. |

Cache keys carry the user id, the permission signature, and the resolved
period + country, so an entry can never cross a permission boundary and
a permission change invalidates immediately.

## Current state vs. period

Attention counts and as-of figures (wallet liability, active instructors,
disputed lessons, integrity checks) **must not move when the period
selector changes**, and the UI says which frame each one uses. Where an
authoritative calculation has no current-state form —
`unfinalizedPastDueCount` is scoped by `starts_at` — the feed pins a
*fixed* rolling 30 days and labels the card accordingly.

## Meeting problems are counted once

Operational alerts are the authoritative source for meeting-creation
failure, cancellation failure and missing meeting links. The attention
feed **partitions** the open-alert set into a lesson-access card and an
everything-else card, and never adds a second meeting card derived from
the Operations report. The Operations report keeps its own meeting
metrics; the dashboard simply does not restate them.

## Drill-down

- **Resource indexes** carry state natively: `?filters[<name>][value]=`,
  `?tab=`, `?search=`, `?sort=` (`Filament\Resources\Pages\ListRecords`).
- **Report pages** now declare their own `#[Url]` bindings, so period,
  country and report-specific filters survive the jump. Each report binds
  only the dimensions it implements. Raw values are untrusted:
  `App\Reporting\Support\ReportPeriodResolver` degrades an invalid or
  oversized custom range to the default instead of throwing, and enum
  values fail safe via `tryFrom()`.
- **Multi-report pages** (Learning Analytics, Wallet & Refunds,
  Referrals & Communications) accept `?section=` via
  `HasReportSectionState`, validated against an allow-list.

Where an entity has only an index page, drill-down ends at the filtered
index and its row actions. No record route is invented.

## Related

- `docs/audits/main-dashboard-content-audit.md` — the audit this
  implementation was built from, including its own corrections section.
- `architecture/overview.md`, `SRS.md` §19 (Reporting & Analytics).
