<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Subject;
use App\Reporting\Contracts\MarketplaceExecutiveReportServiceInterface;
use App\Reporting\DTOs\Marketplace\MarketplaceComparisonData;
use App\Reporting\DTOs\Marketplace\MarketplaceDemandData;
use App\Reporting\DTOs\Marketplace\MarketplaceSupplyData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Marketplace Supply & Demand (SRS Chapter 19). Every
 * figure is delegated to
 * {@see MarketplaceExecutiveReportServiceInterface}; supply is
 * current-state, demand is period-event, comparisons pair compatible
 * dimensions only. Requires `ViewMarketplaceReports`.
 */
class MarketplaceSupplyDemand extends Page
{
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.marketplace-supply-demand';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Marketplace Supply & Demand';

    protected static ?string $title = 'Marketplace Supply & Demand';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 15;

    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    // Livewire hydrates select values as strings — kept ?string, cast
    // once in filters().
    public ?string $countryId = null;

    public ?string $subjectId = null;

    public ?string $instructorId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(MarketplaceExecutiveReportServiceInterface::class)->canViewMarketplace($user);
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'countryId', 'subjectId', 'instructorId']);
        $this->periodPreset = 'last_30_days';
    }

    public function period(): ReportingPeriod
    {
        $preset = ReportingPeriodPreset::tryFrom($this->periodPreset) ?? ReportingPeriodPreset::Last30Days;
        $timezone = ReportingTimezoneResolver::resolve();

        if ($preset === ReportingPeriodPreset::Custom && filled($this->customStart) && filled($this->customEnd)) {
            return ReportingPeriod::custom($this->customStart, $this->customEnd, $timezone);
        }

        return ReportingPeriod::forPreset($preset === ReportingPeriodPreset::Custom ? ReportingPeriodPreset::Last30Days : $preset, $timezone);
    }

    private function filters(): ReportFilters
    {
        return new ReportFilters(
            period: $this->period(),
            countryId: filled($this->countryId) && is_numeric($this->countryId) ? (int) $this->countryId : null,
            subjectId: filled($this->subjectId) ? $this->subjectId : null, // subjects use UUID ids
            instructorId: filled($this->instructorId) && is_numeric($this->instructorId) ? (int) $this->instructorId : null,
        );
    }

    public function supply(): ?MarketplaceSupplyData
    {
        return $this->canViewReport()
            ? app(MarketplaceExecutiveReportServiceInterface::class)->marketplaceSupply(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function demand(): ?MarketplaceDemandData
    {
        return $this->canViewReport()
            ? app(MarketplaceExecutiveReportServiceInterface::class)->marketplaceDemand(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function comparison(): ?MarketplaceComparisonData
    {
        return $this->canViewReport()
            ? app(MarketplaceExecutiveReportServiceInterface::class)->marketplaceComparison(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(MarketplaceExecutiveReportServiceInterface::class)->freshness($this->period());
    }

    public function canViewReport(): bool
    {
        return app(MarketplaceExecutiveReportServiceInterface::class)->canViewMarketplace(auth()->user());
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }

    /** @return Collection<int, Country> */
    public function countryOptions(): Collection
    {
        return Country::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Subject> */
    public function subjectOptions(): Collection
    {
        return Subject::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, AcademicLevel> */
    public function academicLevelOptions(): Collection
    {
        return AcademicLevel::query()->orderBy('name')->get(['id', 'name']);
    }
}
