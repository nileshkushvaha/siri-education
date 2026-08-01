<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Dashboard\DTOs\AttentionFeed;
use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\DTOs\DashboardData;
use App\Dashboard\Services\AttentionFeedService;
use App\Dashboard\Services\DashboardCompositionService;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Models\Country;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\Support\ReportPeriodResolver;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The marketplace command centre.
 *
 * Answers three questions, in this order: what needs attention right
 * now, how the marketplace performed over the selected period, and
 * where to go next. It renders no data tables — the previous
 * registration / login / audit-trail tables were identity-system
 * activity, not marketplace management, and each is one click away in
 * the module that owns it.
 *
 * This page holds only filter state. Every figure is composed by
 * {@see DashboardCompositionService} from existing `App\Reporting`
 * calculation owners, and every attention count by
 * {@see AttentionFeedService}. No business query lives here.
 *
 * The period/country selection is bound to the query string, so a
 * dashboard view is linkable, survives a browser back-navigation, and
 * can be forwarded into a report with its context intact.
 *
 * Extends Filament's `Dashboard` page purely to keep the panel's `/`
 * route; the widget grid is replaced by an explicit Blade layout, so
 * `getWidgets()` returns nothing.
 */
class Dashboard extends BaseDashboard
{
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = -2;

    /**
     * Bound to `?period=`. An unrecognised value falls back to the
     * default inside {@see ReportPeriodResolver}, never throws.
     */
    #[Url(as: 'period')]
    public string $periodPreset = 'last_30_days';

    #[Url(as: 'start')]
    public ?string $customStart = null;

    #[Url(as: 'end')]
    public ?string $customEnd = null;

    /** Livewire hydrates select values as strings; cast once in context(). */
    #[Url(as: 'country')]
    public ?string $countryId = null;

    // ── Page chrome ──────────────────────────────────────────────────

    /**
     * The greeting now lives in the dashboard's own hero, which also
     * owns the Refresh / View Site actions. Filament's page header is
     * left empty so the two do not duplicate each other; breadcrumbs
     * still render above it for orientation.
     */
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    /** Time-of-day greeting, rendered by the hero. */
    public function greeting(): string
    {
        $hour = now()->hour;

        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        $name = auth()->user()?->first_name ?? auth()->user()?->name ?? 'Admin';

        return "{$greeting}, {$name} 👋";
    }

    /**
     * No breadcrumbs. A trail exists to show where you are relative to
     * somewhere else — the dashboard is the root, so "Dashboard" on its own
     * pointed at the page you were already on (`#`) and sat directly above a
     * hero that already says so. The sidebar's own Dashboard item is the
     * orientation cue here.
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** Both actions are rendered by the hero instead — see the context bar partial. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * The Blade layout renders every section explicitly, so the
     * inherited widget grid is deliberately empty. The former
     * dashboard widgets remain registered classes for reuse elsewhere;
     * they are simply no longer part of this page's composition.
     */
    public function getWidgets(): array
    {
        return [];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    // ── Global filter context ────────────────────────────────────────

    /**
     * Resolves the selected period through
     * {@see ReportPeriodResolver}, which enforces
     * `ReportingPeriod`'s own maximum-range and ordering rules and
     * degrades to the default preset rather than letting a hand-edited
     * URL surface a 500.
     */
    public function context(): DashboardContext
    {
        return new DashboardContext(
            period: ReportPeriodResolver::resolve(
                preset: $this->periodPreset,
                customStart: $this->customStart,
                customEnd: $this->customEnd,
                default: ReportingPeriodPreset::Last30Days,
            ),
            countryId: $this->resolvedCountryId(),
        );
    }

    /**
     * A country id from the URL is only honoured when it identifies a
     * country the dashboard actually offers. An unknown or unavailable
     * id is dropped rather than silently producing an empty dashboard
     * that looks like "no activity".
     */
    private function resolvedCountryId(): ?int
    {
        if (! filled($this->countryId) || ! is_numeric($this->countryId)) {
            return null;
        }

        $id = (int) $this->countryId;

        // Validated against the same set the dropdown offers, so a
        // hand-edited URL cannot select a country the UI never showed.
        return Country::query()->availableForRegistration()->whereKey($id)->exists() ? $id : null;
    }

    /** True when a custom range was supplied but rejected, so the view can say so. */
    public function customRangeWasRejected(): bool
    {
        return ReportPeriodResolver::customRangeIsInvalid($this->periodPreset, $this->customStart, $this->customEnd);
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'countryId']);
        $this->periodPreset = ReportingPeriodPreset::Last30Days->value;
    }

    public function refreshDashboard(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        app(DashboardCompositionService::class)->forget($user, $this->context());

        Notification::make()
            ->title('Dashboard refreshed')
            ->body('Figures were recomputed from their owning reports.')
            ->success()
            ->send();
    }

    // ── Data accessors used by the view ──────────────────────────────

    public function dashboard(): DashboardData
    {
        return app(DashboardCompositionService::class)->compose(auth()->user(), $this->context());
    }

    public function attention(): AttentionFeed
    {
        return app(AttentionFeedService::class)->build(auth()->user());
    }

    public function reportingTimezone(): string
    {
        return ReportingTimezoneResolver::resolve();
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }

    /**
     * The same country list the public registration form offers —
     * `Country::availableForRegistration()` is the single owner of that
     * definition (active, with an active default currency).
     *
     * Filtering the dashboard by a country nobody can register in would
     * only ever return an empty result, so offering it would be
     * misleading.
     *
     * @return Collection<int, Country>
     */
    public function countryOptions(): Collection
    {
        return Country::query()
            ->availableForRegistration()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
