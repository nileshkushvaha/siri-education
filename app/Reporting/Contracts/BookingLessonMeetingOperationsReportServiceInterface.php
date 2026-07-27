<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Operations\ActionableLessonRow;
use App\Reporting\DTOs\Operations\BookingOperationsSummaryData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\DTOs\Operations\LessonOutcomeSummaryData;
use App\Reporting\DTOs\Operations\MeetingIssueRow;
use App\Reporting\DTOs\Operations\MeetingOperationsSummaryData;
use App\Reporting\DTOs\Operations\NoShowTechnicalIssueRow;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The single read-only entry point for the Booking, Lesson
 * & Meeting Operations report. Every method independently re-checks
 * authorization (never trusts that the Filament page already checked
 * once) and every filter is restricted to what this report declares
 * support for before being applied. Never mutates a source domain,
 * never dispatches an event, never sends a notification.
 */
interface BookingLessonMeetingOperationsReportServiceInterface
{
    /** @throws AuthorizationException */
    public function bookingSummary(User $user, ReportingPeriod $period, ReportFilters $filters): BookingOperationsSummaryData;

    /** @throws AuthorizationException */
    public function lessonOutcomeSummary(User $user, ReportingPeriod $period, ReportFilters $filters): LessonOutcomeSummaryData;

    /**
     * Requires the separate meeting-report permission on top of base
     * operational access — never implied by booking/lesson access alone.
     *
     * @throws AuthorizationException
     */
    public function meetingSummary(User $user, ReportingPeriod $period, ReportFilters $filters): MeetingOperationsSummaryData;

    /** @return list<LabeledCountRow> */
    public function bookingsBySubject(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return list<LabeledCountRow> */
    public function bookingsByInstructor(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return list<LabeledCountRow> */
    public function bookingsByCountry(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return list<LabeledCountRow> */
    public function bookingsByDuration(User $user, ReportingPeriod $period, ReportFilters $filters): array;

    /** @return LengthAwarePaginator<int, ActionableLessonRow> */
    public function lessonsInPeriod(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, MeetingIssueRow>
     *
     * @throws AuthorizationException
     */
    public function meetingIssues(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, NoShowTechnicalIssueRow> */
    public function noShowAndTechnicalIssueLessons(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData;

    public function canViewBookingLessonSection(User $user): bool;

    public function canViewMeetingSection(User $user): bool;
}
