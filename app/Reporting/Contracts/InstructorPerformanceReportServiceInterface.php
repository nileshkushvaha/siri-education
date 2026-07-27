<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Engagement\DemoConversionData;
use App\Reporting\DTOs\Engagement\InstructorActivitySummaryData;
use App\Reporting\DTOs\Engagement\InstructorLifecycleSummaryData;
use App\Reporting\DTOs\Engagement\InstructorPerformanceRow;
use App\Reporting\DTOs\Engagement\InstructorQualitySummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The single read-only entry point for Instructor
 * Performance reporting. Base access requires `ViewInstructorReports`;
 * quality figures additionally require `ViewReviewQualityReports` —
 * never implied. Earnings/compensation/settlement data is structurally
 * absent from every DTO. Never mutates a source domain.
 */
interface InstructorPerformanceReportServiceInterface
{
    /** @throws AuthorizationException */
    public function lifecycleSummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorLifecycleSummaryData;

    /** @throws AuthorizationException */
    public function activitySummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorActivitySummaryData;

    /**
     * Requires `ViewReviewQualityReports` on top of base access.
     *
     * @throws AuthorizationException
     */
    public function qualitySummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorQualitySummaryData;

    /** Reuses the existing BookingAnalytics conversion definition verbatim (§6.6 Outcome A). @throws AuthorizationException */
    public function demoConversion(User $user, ReportingPeriod $period): DemoConversionData;

    /**
     * @return LengthAwarePaginator<int, InstructorPerformanceRow>
     *
     * @throws AuthorizationException
     */
    public function performanceRows(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData;

    public function canView(User $user): bool;

    public function canViewQuality(User $user): bool;
}
