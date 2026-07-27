<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Pages\Concerns\ExportsReportCsv;
use App\Homework\Enums\HomeworkStatus;
use App\Models\AcademicLevel;
use App\Models\Subject;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Learning\HomeworkAnalyticsData;
use App\Reporting\DTOs\Learning\LearningGoalAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanAnalyticsData;
use App\Reporting\DTOs\Learning\LearningTrendsData;
use App\Reporting\DTOs\Learning\MilestoneReviewAnalyticsData;
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
 * Learning Analytics (SRS Chapter 19 Learning Dashboard,
 * Learning Plan Report, Homework Report). Every figure is delegated to
 * {@see LearningAnalyticsReportServiceInterface}; this page holds only
 * filter state and never queries an academic domain directly. Requires
 * `ViewLearningReports` — independent of student, instructor and
 * finance report gates.
 */
class LearningAnalytics extends Page
{
    use ExportsReportCsv;
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.learning-analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Learning Analytics';

    protected static ?string $title = 'Learning Analytics';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 13;

    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    // Livewire hydrates select values as strings — kept ?string, cast
    // once in filters().
    public ?string $subjectId = null;

    public ?string $educationLevelId = null;

    public ?string $planStatus = null;

    public ?string $goalStatus = null;

    public ?string $homeworkStatus = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('learning_progress');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'subjectId', 'educationLevelId', 'planStatus', 'goalStatus', 'homeworkStatus']);
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
            subjectId: filled($this->subjectId) ? $this->subjectId : null, // subjects use UUID ids
            educationLevelId: filled($this->educationLevelId) ? $this->educationLevelId : null,
            learningPlanStatus: filled($this->planStatus) ? LearningPlanStatus::tryFrom($this->planStatus) : null,
            learningGoalStatus: filled($this->goalStatus) ? LearningGoalStatus::tryFrom($this->goalStatus) : null,
            homeworkStatus: filled($this->homeworkStatus) ? HomeworkStatus::tryFrom($this->homeworkStatus) : null,
        );
    }

    public function planSummary(): ?LearningPlanAnalyticsData
    {
        return $this->canViewReport()
            ? app(LearningAnalyticsReportServiceInterface::class)->planSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function goalSummary(): ?LearningGoalAnalyticsData
    {
        return $this->canViewReport()
            ? app(LearningAnalyticsReportServiceInterface::class)->goalSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function homeworkSummary(): ?HomeworkAnalyticsData
    {
        return $this->canViewReport()
            ? app(LearningAnalyticsReportServiceInterface::class)->homeworkSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function milestoneReviewSummary(): ?MilestoneReviewAnalyticsData
    {
        return $this->canViewReport()
            ? app(LearningAnalyticsReportServiceInterface::class)->milestoneReviewSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function trends(): ?LearningTrendsData
    {
        return $this->canViewReport()
            ? app(LearningAnalyticsReportServiceInterface::class)->trends(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function planReviewRows(): ?LengthAwarePaginator
    {
        return $this->canViewReport()
            ? app(LearningAnalyticsReportServiceInterface::class)->planReviewTable(auth()->user(), $this->period(), $this->filters(), 10)
            : null;
    }

    public function homeworkAttentionRows(): ?LengthAwarePaginator
    {
        return $this->canViewReport()
            ? app(LearningAnalyticsReportServiceInterface::class)->homeworkAttentionTable(auth()->user(), $this->period(), $this->filters(), 10)
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(LearningAnalyticsReportServiceInterface::class)->freshness($this->period());
    }

    public function canViewReport(): bool
    {
        return app(LearningAnalyticsReportServiceInterface::class)->canView(auth()->user());
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }

    /** @return Collection<int, LearningPlanStatus> */
    public function planStatusOptions(): Collection
    {
        return collect(LearningPlanStatus::cases());
    }

    /** @return Collection<int, LearningGoalStatus> */
    public function goalStatusOptions(): Collection
    {
        return collect(LearningGoalStatus::cases());
    }

    /** @return Collection<int, HomeworkStatus> */
    public function homeworkStatusOptions(): Collection
    {
        return collect(HomeworkStatus::cases());
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
