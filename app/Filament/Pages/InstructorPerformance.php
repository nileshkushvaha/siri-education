<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InstructorStatus;
use App\Models\Country;
use App\Models\Subject;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Engagement\DemoConversionData;
use App\Reporting\DTOs\Engagement\InstructorActivitySummaryData;
use App\Reporting\DTOs\Engagement\InstructorLifecycleSummaryData;
use App\Reporting\DTOs\Engagement\InstructorQualitySummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Phase 18D — Instructor Performance report (SRS Chapter 19 Instructor
 * Analytics). Delegates every figure to
 * {@see InstructorPerformanceReportServiceInterface}. Requires
 * `ViewInstructorReports`; the quality section additionally requires
 * `ViewReviewQualityReports`. No earnings/compensation data anywhere.
 */
class InstructorPerformance extends Page
{
    protected string $view = 'filament.pages.instructor-performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Instructor Performance';

    protected static ?string $title = 'Instructor Performance';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 8;

    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public ?string $countryId = null;

    public ?string $subjectId = null;

    public ?string $instructorId = null;

    public ?string $instructorStatus = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('instructor_performance');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'countryId', 'subjectId', 'instructorId', 'instructorStatus']);
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
            instructorStatus: filled($this->instructorStatus) ? InstructorStatus::tryFrom($this->instructorStatus) : null,
        );
    }

    public function canViewReport(): bool
    {
        return app(InstructorPerformanceReportServiceInterface::class)->canView(auth()->user());
    }

    public function canViewQuality(): bool
    {
        return app(InstructorPerformanceReportServiceInterface::class)->canViewQuality(auth()->user());
    }

    public function lifecycle(): ?InstructorLifecycleSummaryData
    {
        return $this->canViewReport()
            ? app(InstructorPerformanceReportServiceInterface::class)->lifecycleSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function activity(): ?InstructorActivitySummaryData
    {
        return $this->canViewReport()
            ? app(InstructorPerformanceReportServiceInterface::class)->activitySummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function quality(): ?InstructorQualitySummaryData
    {
        return $this->canViewQuality()
            ? app(InstructorPerformanceReportServiceInterface::class)->qualitySummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function conversion(): ?DemoConversionData
    {
        return $this->canViewReport()
            ? app(InstructorPerformanceReportServiceInterface::class)->demoConversion(auth()->user(), $this->period())
            : null;
    }

    public function performanceRows(): ?LengthAwarePaginator
    {
        return $this->canViewReport()
            ? app(InstructorPerformanceReportServiceInterface::class)->performanceRows(auth()->user(), $this->period(), $this->filters(), 10)
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(InstructorPerformanceReportServiceInterface::class)->freshness($this->period());
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }

    /** @return Collection<int, InstructorStatus> */
    public function instructorStatusOptions(): Collection
    {
        return collect(InstructorStatus::cases());
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
}
