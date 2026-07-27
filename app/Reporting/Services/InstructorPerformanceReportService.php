<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Booking\Contracts\BookingAnalyticsRepositoryInterface;
use App\Enums\InstructorStatus;
use App\Filament\Resources\Users\UserResource;
use App\Models\InstructorRatingAggregate;
use App\Models\User;
use App\Quality\Contracts\AdminQualityDashboardRepositoryInterface;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Engagement\DemoConversionData;
use App\Reporting\DTOs\Engagement\InstructorActivitySummaryData;
use App\Reporting\DTOs\Engagement\InstructorLifecycleSummaryData;
use App\Reporting\DTOs\Engagement\InstructorPerformanceRow;
use App\Reporting\DTOs\Engagement\InstructorQualitySummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\InstructorPerformanceRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Ratings reuse the instructor rating aggregate's
 * own accessors (`InstructorRatingAggregate::overallAverage()`) and
 * `AdminQualityDashboardRepositoryInterface::platformAverageRating()`
 * — the existing calculation owners, never recomputed here. Demo
 * conversion reuses `BookingAnalyticsRepositoryInterface::conversion()`
 * verbatim (§6.6 Outcome A).
 */
final class InstructorPerformanceReportService implements InstructorPerformanceReportServiceInterface
{
    private const string REPORT_KEY = 'instructor_performance';

    public function __construct(
        private readonly InstructorPerformanceRepository $repository,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportRegistryInterface $registry,
        private readonly BookingAnalyticsRepositoryInterface $bookingAnalytics,
        private readonly AdminQualityDashboardRepositoryInterface $qualityDashboard,
    ) {}

    public function lifecycleSummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorLifecycleSummaryData
    {
        $this->authorize($user);

        return $this->repository->lifecycleSummary($period, $this->restrict($filters));
    }

    public function activitySummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorActivitySummaryData
    {
        $this->authorize($user);

        return $this->repository->activitySummary($period, $this->restrict($filters));
    }

    public function qualitySummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorQualitySummaryData
    {
        $this->authorizeQuality($user);

        return new InstructorQualitySummaryData(
            platformAverageRating: $this->qualityDashboard->platformAverageRating(),
            instructorsWithPublishedRatings: $this->qualityDashboard->instructorsWithPublishedRatingsCount(),
            activeQualityAlerts: (int) DB::table('quality_alerts')->whereIn('status', ['open', 'under_review'])->count(),
        );
    }

    public function demoConversion(User $user, ReportingPeriod $period): DemoConversionData
    {
        $this->authorize($user);

        $conversion = $this->bookingAnalytics->conversion($period->startUtc, $period->endUtcExclusive);
        $demoBookers = (int) $conversion->demo_bookers;
        $converted = (int) $conversion->converted;

        return new DemoConversionData(
            demoBookers: $demoBookers,
            convertedBookers: $converted,
            conversionRate: $demoBookers > 0 ? round($converted / $demoBookers * 100, 1) : null,
        );
    }

    public function performanceRows(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($user);

        $includeQuality = $this->canViewQuality($user);
        $paginator = $this->repository->paginatedInstructors($period, $this->restrict($filters), $perPage);

        /** @var list<int> $ids */
        $ids = collect($paginator->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $bookingCounts = $this->repository->bookingTypeCountsFor($ids, $period);
        $outcomeCounts = $this->repository->outcomeCountsFor($ids, $period);
        $uniqueStudents = $this->repository->uniqueStudentsFor($ids, $period);
        $bookedHours = $this->repository->bookedHoursFor($ids, $period);
        $aggregates = InstructorRatingAggregate::query()->whereIn('instructor_id', $ids)->get()->keyBy('instructor_id');
        $alerts = $includeQuality ? $this->repository->activeQualityAlertsFor($ids) : [];

        return $paginator->through(function (User $instructor) use ($user, $includeQuality, $bookingCounts, $outcomeCounts, $uniqueStudents, $bookedHours, $aggregates, $alerts): InstructorPerformanceRow {
            $id = (int) $instructor->id;
            $aggregate = $aggregates->get($id);

            return new InstructorPerformanceRow(
                instructorId: $id,
                instructorLabel: $instructor->full_name,
                statusLabel: ($instructor->profile?->instructor_status ?? InstructorStatus::Draft)->label(),
                countryLabel: $instructor->profile?->country?->name,
                demoBookings: $bookingCounts[$id]['demo'] ?? 0,
                paidBookings: $bookingCounts[$id]['paid'] ?? 0,
                completedLessons: $outcomeCounts[$id]['completed'] ?? 0,
                uniqueStudents: $uniqueStudents[$id] ?? 0,
                instructorNoShows: $outcomeCounts[$id]['instructor_no_show'] ?? 0,
                bookedHours: $bookedHours[$id] ?? 0.0,
                averageRating: $aggregate?->overallAverage(),
                reviewCount: (int) ($aggregate?->eligible_review_count ?? 0),
                activeQualityAlerts: $includeQuality ? ($alerts[$id] ?? 0) : null,
                drillDownUrl: $this->drillDownUrl($user, $instructor),
            );
        });
    }

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData
    {
        return new OperationsReportFreshnessData(
            freshness: ReportDataFreshness::Live,
            generatedAt: CarbonImmutable::now(),
            reportingTimezone: $period->timezone,
            periodLabel: $period->label,
        );
    }

    public function canView(User $user): bool
    {
        try {
            $this->authorize($user);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function canViewQuality(User $user): bool
    {
        try {
            $this->authorizeQuality($user);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function restrict(ReportFilters $filters): ReportFilters
    {
        $definition = $this->registry->find(self::REPORT_KEY);

        return $filters->restrictedTo($definition?->supportedFilters ?? []);
    }

    /** @throws AuthorizationException */
    private function authorize(User $user): void
    {
        $definition = $this->registry->find(self::REPORT_KEY);

        if ($definition === null || ! $this->access->canView($user, $definition)) {
            throw new AuthorizationException('You may not view instructor performance reporting.');
        }

        if (! $this->hasPermission($user, 'ViewInstructorReports')) {
            throw new AuthorizationException('You may not view instructor performance reporting.');
        }
    }

    /** @throws AuthorizationException */
    private function authorizeQuality(User $user): void
    {
        $this->authorize($user);

        if (! $this->hasPermission($user, 'ViewReviewQualityReports')) {
            throw new AuthorizationException('You may not view instructor quality reporting.');
        }
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function drillDownUrl(User $viewer, User $instructor): ?string
    {
        if (! Gate::forUser($viewer)->allows('view', $instructor)) {
            return null;
        }

        return UserResource::getUrl('view', ['record' => $instructor]);
    }
}
