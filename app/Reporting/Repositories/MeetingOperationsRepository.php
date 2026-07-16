<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\LessonAttendanceRecord;
use App\Models\LessonTechnicalIssueReport;
use App\Reporting\DTOs\Operations\MeetingOperationsSummaryData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only aggregate queries over `booking_meetings`/`lesson_attendance_records`/
 * `lesson_technical_issue_reports` (Phase 18C). Date basis:
 * `booking_meetings.created_at` for created/failed; `bookings.starts_at`
 * for missing-meeting (a confirmed booking whose scheduled start falls
 * in the period with no `Created` meeting); the lesson's `starts_at`
 * for join counts and technical-issue reports (both are lesson-scoped
 * concepts). A null join-evidence timestamp is never counted as an
 * absence — only as "no evidence yet".
 */
final class MeetingOperationsRepository
{
    public function summary(ReportingPeriod $period, ReportFilters $filters): MeetingOperationsSummaryData
    {
        return new MeetingOperationsSummaryData(
            created: $this->countByStatus($period, $filters, MeetingStatus::Created),
            failed: $this->countByStatus($period, $filters, MeetingStatus::Failed),
            missingMeeting: $this->missingMeetingCount($period, $filters),
            studentJoined: $this->joinedCount($period, $filters, 'student'),
            instructorJoined: $this->joinedCount($period, $filters, 'instructor'),
            bothJoined: $this->bothJoinedCount($period, $filters),
            technicalIssueReports: $this->technicalIssueReportCount($period, $filters),
        );
    }

    public function countByStatus(ReportingPeriod $period, ReportFilters $filters, MeetingStatus $status): int
    {
        $query = BookingMeeting::query()
            ->where('status', $status)
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive);

        if ($filters->instructorId !== null || $filters->countryId !== null || $filters->bookingType !== null) {
            $query->whereHas('booking', fn (Builder $q) => $this->applyBookingScopedFilters($q, $filters));
        }

        return $query->count();
    }

    /** Confirmed bookings whose scheduled start falls in the period with no successfully-created meeting. */
    public function missingMeetingCount(ReportingPeriod $period, ReportFilters $filters): int
    {
        $query = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>=', $period->startUtc)
            ->where('starts_at', '<', $period->endUtcExclusive)
            ->whereDoesntHave('meeting', fn (Builder $q) => $q->where('status', MeetingStatus::Created));

        $this->applyBookingScopedFilters($query, $filters);

        return $query->count();
    }

    /** @param 'student'|'instructor' $participant */
    public function joinedCount(ReportingPeriod $period, ReportFilters $filters, string $participant): int
    {
        $column = "{$participant}_first_joined_at";

        $query = LessonAttendanceRecord::query()
            ->whereNotNull($column)
            ->whereHas('lesson', fn (Builder $q) => $this->scopedLesson($q, $period, $filters));

        return $query->count();
    }

    public function bothJoinedCount(ReportingPeriod $period, ReportFilters $filters): int
    {
        $query = LessonAttendanceRecord::query()
            ->whereNotNull('student_first_joined_at')
            ->whereNotNull('instructor_first_joined_at')
            ->whereHas('lesson', fn (Builder $q) => $this->scopedLesson($q, $period, $filters));

        return $query->count();
    }

    public function technicalIssueReportCount(ReportingPeriod $period, ReportFilters $filters): int
    {
        $query = LessonTechnicalIssueReport::query()
            ->where('submitted_at', '>=', $period->startUtc)
            ->where('submitted_at', '<', $period->endUtcExclusive);

        if ($filters->instructorId !== null || $filters->studentId !== null || $filters->subjectId !== null) {
            $query->whereHas('lesson', fn (Builder $q) => $this->applyLessonScopedFilters($q, $filters));
        }

        return $query->count();
    }

    /** Bounded, paginated — confirmed bookings in the period whose meeting failed or is missing. */
    public function paginatedMeetingIssues(ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        // A meeting row missing entirely, or present but not in the
        // successfully-created state (pending/failed/cancelled), are both
        // "issues" — a single whereDoesntHave(status = Created) covers both.
        $query = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>=', $period->startUtc)
            ->where('starts_at', '<', $period->endUtcExclusive)
            ->whereDoesntHave('meeting', fn (Builder $m) => $m->where('status', MeetingStatus::Created));

        $this->applyBookingScopedFilters($query, $filters);

        return $query
            ->with(['type', 'meeting', 'student', 'instructor'])
            ->orderBy('starts_at')
            ->paginate($perPage);
    }

    private function scopedLesson(Builder $query, ReportingPeriod $period, ReportFilters $filters): Builder
    {
        $query->where('starts_at', '>=', $period->startUtc)
            ->where('starts_at', '<', $period->endUtcExclusive);

        return $this->applyLessonScopedFilters($query, $filters);
    }

    private function applyLessonScopedFilters(Builder $query, ReportFilters $filters): Builder
    {
        if ($filters->instructorId !== null) {
            $query->where('instructor_id', $filters->instructorId);
        }

        if ($filters->studentId !== null) {
            $query->where('student_id', $filters->studentId);
        }

        if ($filters->subjectId !== null) {
            $query->where('subject_id', $filters->subjectId);
        }

        return $query;
    }

    private function applyBookingScopedFilters(Builder $query, ReportFilters $filters): Builder
    {
        if ($filters->instructorId !== null) {
            $query->where('bookings.instructor_id', $filters->instructorId);
        }

        if ($filters->bookingType !== null) {
            $query->whereHas('type', fn (Builder $q) => $q->where('key', $filters->bookingType->value));
        }

        if ($filters->countryId !== null) {
            $query->whereHas(
                'student',
                fn (Builder $q) => $q->whereHas('profile', fn (Builder $p) => $p->where('country_id', $filters->countryId)),
            );
        }

        return $query;
    }
}
