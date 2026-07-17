<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\StudentStatus;
use App\Filament\Pages\Concerns\ExportsReportCsv;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\Engagement\StudentEngagementSummaryData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
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
 * Phase 18D — Student Engagement report (SRS Chapter 19 Student
 * Analytics). Every figure is delegated to
 * {@see StudentEngagementReportServiceInterface}; this page holds only
 * filter state and never queries a source domain directly. Requires
 * `ViewStudentReports` — independent of the instructor report's gate.
 */
class StudentEngagement extends Page
{
    use ExportsReportCsv;

    protected string $view = 'filament.pages.student-engagement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Student Engagement';

    protected static ?string $title = 'Student Engagement';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 7;

    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    // Livewire hydrates select/number values as strings — kept ?string,
    // cast once in filters() (Phase 18C hydration-regression lesson).
    public ?string $countryId = null;

    public ?string $educationLevelId = null;

    public ?string $studentStatus = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('student_engagement');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'countryId', 'educationLevelId', 'studentStatus']);
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
            educationLevelId: filled($this->educationLevelId) ? $this->educationLevelId : null, // academic levels use UUID ids
            studentStatus: filled($this->studentStatus) ? StudentStatus::tryFrom($this->studentStatus) : null,
        );
    }

    public function summary(): ?StudentEngagementSummaryData
    {
        return $this->canViewReport()
            ? app(StudentEngagementReportServiceInterface::class)->summary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    /** @return array<string, list<LabeledCountRow>> */
    public function breakdowns(): array
    {
        if (! $this->canViewReport()) {
            return [];
        }

        $service = app(StudentEngagementReportServiceInterface::class);
        $user = auth()->user();

        return [
            'By country (current profile)' => $service->byCountry($user, $this->period(), $this->filters()),
            'By academic level (current profile)' => $service->byAcademicLevel($user, $this->period(), $this->filters()),
            'By preferred subject (self-selected preference)' => $service->byPreferredSubject($user, $this->period(), $this->filters()),
            'By booked subject (lessons in period)' => $service->byBookedSubject($user, $this->period(), $this->filters()),
        ];
    }

    /** @return array<string, int> */
    public function registrationTrend(): array
    {
        return $this->canViewReport()
            ? app(StudentEngagementReportServiceInterface::class)->registrationTrend(auth()->user(), $this->period(), $this->filters())
            : [];
    }

    public function engagementRows(): ?LengthAwarePaginator
    {
        return $this->canViewReport()
            ? app(StudentEngagementReportServiceInterface::class)->engagementRows(auth()->user(), $this->period(), $this->filters(), 10)
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(StudentEngagementReportServiceInterface::class)->freshness($this->period());
    }

    public function canViewReport(): bool
    {
        return app(StudentEngagementReportServiceInterface::class)->canView(auth()->user());
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }

    /** @return Collection<int, StudentStatus> */
    public function studentStatusOptions(): Collection
    {
        return collect(StudentStatus::cases());
    }

    /** @return Collection<int, Country> */
    public function countryOptions(): Collection
    {
        return Country::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, AcademicLevel> */
    public function academicLevelOptions(): Collection
    {
        return AcademicLevel::query()->orderBy('name')->get(['id', 'name']);
    }
}
