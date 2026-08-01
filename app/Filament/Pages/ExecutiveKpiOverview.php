<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Reporting\Contracts\MarketplaceExecutiveReportServiceInterface;
use App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportPeriodResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Executive KPI Overview: a pure composition of existing
 * report-service results (each group keeps its own owner, permission
 * and definitions). Requires `ViewExecutiveReports`; groups whose
 * underlying permission is absent render as restricted and never
 * query. Deliberately limited filters (period only beyond the
 * defaults) — partial executive totals mislead.
 */
class ExecutiveKpiOverview extends Page
{
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.executive-kpi-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'Executive KPI Overview';

    protected static ?string $title = 'Executive KPI Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 16;

    #[Url(as: 'period')]
    public string $periodPreset = 'this_month';

    #[Url(as: 'start')]
    public ?string $customStart = null;

    #[Url(as: 'end')]
    public ?string $customEnd = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(MarketplaceExecutiveReportServiceInterface::class)->canViewExecutive($user);
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd']);
        $this->periodPreset = 'this_month';
    }

    public function period(): ReportingPeriod
    {
        return ReportPeriodResolver::resolve(
            preset: $this->periodPreset,
            customStart: $this->customStart,
            customEnd: $this->customEnd,
            default: ReportingPeriodPreset::ThisMonth,
        );
    }

    public function overview(): ?ExecutiveKpiOverviewData
    {
        return $this->canViewReport()
            ? app(MarketplaceExecutiveReportServiceInterface::class)->executiveOverview(
                auth()->user(),
                $this->period(),
                new ReportFilters(period: $this->period()),
            )
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(MarketplaceExecutiveReportServiceInterface::class)->freshness($this->period());
    }

    public function canViewReport(): bool
    {
        return app(MarketplaceExecutiveReportServiceInterface::class)->canViewExecutive(auth()->user());
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }
}
