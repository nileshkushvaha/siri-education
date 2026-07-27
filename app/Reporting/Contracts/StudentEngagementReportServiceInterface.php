<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Engagement\StudentEngagementRow;
use App\Reporting\DTOs\Engagement\StudentEngagementSummaryData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The single read-only entry point for Student Engagement
 * reporting. Every method independently re-authorizes
 * (`ViewStudentReports`), restricts filters to the report definition,
 * masks identity server-side, and never mutates a source domain or
 * dispatches an event. Financial data is structurally absent.
 */
interface StudentEngagementReportServiceInterface
{
    /** @throws AuthorizationException */
    public function summary(User $user, ReportingPeriod $period, ReportFilters $filters): StudentEngagementSummaryData;

    /** @return list<LabeledCountRow> current profile country (current-state attribute). @throws AuthorizationException */
    public function byCountry(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return list<LabeledCountRow> current academic level (current-state attribute). @throws AuthorizationException */
    public function byAcademicLevel(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return list<LabeledCountRow> self-selected preference — never labeled as a learned subject. @throws AuthorizationException */
    public function byPreferredSubject(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return list<LabeledCountRow> actually booked subject via lessons in period (historical-event attribute). @throws AuthorizationException */
    public function byBookedSubject(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return array<string, int> zero-filled daily registration counts in reporting timezone. @throws AuthorizationException */
    public function registrationTrend(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /**
     * @return LengthAwarePaginator<int, StudentEngagementRow>
     *
     * @throws AuthorizationException
     */
    public function engagementRows(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData;

    public function canView(User $user): bool;
}
