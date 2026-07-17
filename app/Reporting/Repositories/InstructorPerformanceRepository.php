<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Enums\InstructorStatus;
use App\Models\User;
use App\Reporting\DTOs\Engagement\InstructorActivitySummaryData;
use App\Reporting\DTOs\Engagement\InstructorLifecycleSummaryData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries for the Instructor Performance report
 * (Phase 18D). "Instructor" = a user holding the `instructor` role.
 * Lifecycle counts are the CURRENT `user_profiles.instructor_status`
 * (all 11 cases, none collapsed). Approvals-in-period use the
 * structured `activity_log` event `application_approved` (log name
 * `instructor`) — a typed event key, never message-text parsing.
 * Booking/lesson definitions match Phase 18C's operations report
 * exactly (same date bases, same enums).
 */
final class InstructorPerformanceRepository
{
    public function lifecycleSummary(ReportingPeriod $period, ReportFilters $filters): InstructorLifecycleSummaryData
    {
        return new InstructorLifecycleSummaryData(
            total: $this->instructors($filters)->count(),
            byStatus: $this->byStatus($filters),
            newAccountsInPeriod: $this->instructors($filters)
                ->where('users.created_at', '>=', $period->startUtc)
                ->where('users.created_at', '<', $period->endUtcExclusive)
                ->count(),
            applicationsSubmittedInPeriod: $this->instructors($filters)
                ->whereHas('profile', fn (Builder $q) => $q
                    ->where('instructor_application_submitted_at', '>=', $period->startUtc)
                    ->where('instructor_application_submitted_at', '<', $period->endUtcExclusive))
                ->count(),
            approvalsInPeriod: $this->approvalsInPeriod($period),
        );
    }

    /** @return array<string, int> keyed by InstructorStatus::value — every case present, zero-filled. */
    public function byStatus(ReportFilters $filters): array
    {
        $rows = $this->instructors($filters)
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->selectRaw("COALESCE(user_profiles.instructor_status, 'unknown') as status_key, count(*) as aggregate")
            ->groupBy('status_key')
            ->pluck('aggregate', 'status_key');

        $result = array_fill_keys(array_map(fn (InstructorStatus $s) => $s->value, InstructorStatus::cases()), 0);

        foreach ($rows as $status => $count) {
            $result[$status] = (int) $count;
        }

        return $result;
    }

    /** Structured audit events only — `activity_log.event = 'application_approved'`, log name `instructor`. */
    public function approvalsInPeriod(ReportingPeriod $period): int
    {
        return DB::table('activity_log')
            ->where('log_name', 'instructor')
            ->where('event', 'application_approved')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->distinct('subject_id')
            ->count('subject_id');
    }

    public function activitySummary(ReportingPeriod $period, ReportFilters $filters): InstructorActivitySummaryData
    {
        $bookingCounts = $this->bookingTypeCounts($period, $filters);
        $outcomeCounts = $this->outcomeCounts($period, $filters);

        return new InstructorActivitySummaryData(
            demoBookings: $bookingCounts['free_demo'] ?? 0,
            paidBookings: $bookingCounts['paid_one_to_one'] ?? 0,
            completedLessons: $outcomeCounts['completed'] ?? 0,
            studentNoShows: $outcomeCounts['student_no_show'] ?? 0,
            instructorNoShows: $outcomeCounts['instructor_no_show'] ?? 0,
            technicalIssues: $outcomeCounts['technical_issue'] ?? 0,
            cancelledBookings: (int) $this->scopedBookings($period, $filters)->where('bookings.status', 'cancelled')->count(),
            uniqueStudents: (int) $this->scopedBookings($period, $filters)
                ->where('bookings.status', '!=', 'cancelled')
                ->distinct('bookings.student_id')
                ->count('bookings.student_id'),
            uniquePaidStudents: (int) $this->scopedBookings($period, $filters)
                ->where('bookings.status', '!=', 'cancelled')
                ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
                ->where('booking_types.is_paid', true)
                ->distinct('bookings.student_id')
                ->count('bookings.student_id'),
            repeatPaidStudents: $this->repeatPaidStudents($filters),
            bookedTeachingHours: $this->bookedTeachingHours($period, $filters),
            publishedWeeklyAvailabilityHours: $this->publishedWeeklyAvailabilityHours($filters),
        );
    }

    /**
     * §6.7 proxy (never labeled retention): distinct students holding
     * ≥2 LIFETIME non-cancelled paid bookings with the same instructor.
     */
    public function repeatPaidStudents(ReportFilters $filters): int
    {
        $pairs = DB::table('bookings')
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->whereNull('bookings.deleted_at')
            ->where('bookings.status', '!=', 'cancelled')
            ->where('booking_types.is_paid', true)
            ->whereIn('bookings.instructor_id', $this->instructors($filters)->select('users.id'))
            ->selectRaw('bookings.instructor_id, bookings.student_id')
            ->groupBy('bookings.instructor_id', 'bookings.student_id')
            ->havingRaw('count(*) >= 2');

        return (int) DB::query()->fromSub($pairs, 'pairs')->distinct('pairs.student_id')->count('pairs.student_id');
    }

    /** Sum of scheduled durations for Confirmed/Completed bookings whose scheduled start falls in the period — fully source-backed. */
    public function bookedTeachingHours(ReportingPeriod $period, ReportFilters $filters): float
    {
        $minutes = (int) DB::table('bookings')
            ->whereNull('bookings.deleted_at')
            ->whereIn('bookings.status', ['confirmed', 'completed'])
            ->where('bookings.starts_at', '>=', $period->startUtc)
            ->where('bookings.starts_at', '<', $period->endUtcExclusive)
            ->whereIn('bookings.instructor_id', $this->instructors($filters)->select('users.id'))
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(MINUTE, bookings.starts_at, bookings.ends_at)), 0) as minutes')
            ->value('minutes');

        return round($minutes / 60, 1);
    }

    /**
     * CURRENT-STATE weekly published availability (§6.5 Outcome C —
     * never a historical denominator, never divided into a utilization
     * rate). Sums active `teacher_availability` windows only.
     */
    public function publishedWeeklyAvailabilityHours(ReportFilters $filters): float
    {
        $minutes = (int) DB::table('teacher_availability')
            ->where('is_active', true)
            ->whereIn('teacher_id', $this->instructors($filters)->select('users.id'))
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)), 0) as minutes')
            ->value('minutes');

        return round($minutes / 60, 1);
    }

    /** Paginated instructor rows, ordered by in-period paid-booking activity — page ids feed the batch stat loaders below. */
    public function paginatedInstructors(ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->instructors($filters)
            ->with(['profile.country'])
            ->select('users.*')
            ->selectSub(
                DB::table('bookings')
                    ->whereColumn('bookings.instructor_id', 'users.id')
                    ->whereNull('bookings.deleted_at')
                    ->where('bookings.created_at', '>=', $period->startUtc)
                    ->where('bookings.created_at', '<', $period->endUtcExclusive)
                    ->selectRaw('count(*)'),
                'bookings_in_period',
            )
            ->orderByDesc('bookings_in_period')
            ->orderBy('users.id')
            ->paginate($perPage);
    }

    /**
     * @param  list<int>  $instructorIds
     * @return array<int, array{demo: int, paid: int}>
     */
    public function bookingTypeCountsFor(array $instructorIds, ReportingPeriod $period): array
    {
        $rows = DB::table('bookings')
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->whereNull('bookings.deleted_at')
            ->where('bookings.created_at', '>=', $period->startUtc)
            ->where('bookings.created_at', '<', $period->endUtcExclusive)
            ->whereIn('bookings.instructor_id', $instructorIds)
            ->selectRaw('bookings.instructor_id, booking_types.key as type_key, count(*) as aggregate')
            ->groupBy('bookings.instructor_id', 'booking_types.key')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $slot = $row->type_key === 'free_demo' ? 'demo' : 'paid';
            $result[(int) $row->instructor_id][$slot] = ($result[(int) $row->instructor_id][$slot] ?? 0) + (int) $row->aggregate;
        }

        return $result;
    }

    /**
     * @param  list<int>  $instructorIds
     * @return array<int, array<string, int>> instructor id => outcome value => count (finalized lessons in period)
     */
    public function outcomeCountsFor(array $instructorIds, ReportingPeriod $period): array
    {
        $rows = DB::table('lessons')
            ->whereNull('lessons.deleted_at')
            ->whereNotNull('lessons.outcome_finalized_at')
            ->where('lessons.outcome', '!=', 'pending')
            ->where('lessons.outcome_finalized_at', '>=', $period->startUtc)
            ->where('lessons.outcome_finalized_at', '<', $period->endUtcExclusive)
            ->whereIn('lessons.instructor_id', $instructorIds)
            ->selectRaw('lessons.instructor_id, lessons.outcome, count(*) as aggregate')
            ->groupBy('lessons.instructor_id', 'lessons.outcome')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->instructor_id][(string) $row->outcome] = (int) $row->aggregate;
        }

        return $result;
    }

    /**
     * @param  list<int>  $instructorIds
     * @return array<int, int> instructor id => distinct non-cancelled students in period
     */
    public function uniqueStudentsFor(array $instructorIds, ReportingPeriod $period): array
    {
        return DB::table('bookings')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->whereIn('instructor_id', $instructorIds)
            ->selectRaw('instructor_id, count(distinct student_id) as aggregate')
            ->groupBy('instructor_id')
            ->pluck('aggregate', 'instructor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $instructorIds
     * @return array<int, float> instructor id => booked hours in period
     */
    public function bookedHoursFor(array $instructorIds, ReportingPeriod $period): array
    {
        return DB::table('bookings')
            ->whereNull('deleted_at')
            ->whereIn('status', ['confirmed', 'completed'])
            ->where('starts_at', '>=', $period->startUtc)
            ->where('starts_at', '<', $period->endUtcExclusive)
            ->whereIn('instructor_id', $instructorIds)
            ->selectRaw('instructor_id, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)), 0) as minutes')
            ->groupBy('instructor_id')
            ->pluck('minutes', 'instructor_id')
            ->map(fn ($v) => round(((int) $v) / 60, 1))
            ->all();
    }

    /**
     * @param  list<int>  $instructorIds
     * @return array<int, int> instructor id => active (Open/UnderReview) quality alerts
     */
    public function activeQualityAlertsFor(array $instructorIds): array
    {
        return DB::table('quality_alerts')
            ->whereIn('instructor_id', $instructorIds)
            ->whereIn('status', ['open', 'under_review'])
            ->selectRaw('instructor_id, count(*) as aggregate')
            ->groupBy('instructor_id')
            ->pluck('aggregate', 'instructor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** The single authoritative "instructor" base: users holding the instructor role, with shared filters applied. */
    private function instructors(ReportFilters $filters): Builder
    {
        $query = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'instructor'));

        if ($filters->instructorId !== null) {
            $query->whereKey($filters->instructorId);
        }

        if ($filters->countryId !== null || $filters->instructorStatus !== null) {
            $query->whereHas('profile', function (Builder $q) use ($filters): void {
                if ($filters->countryId !== null) {
                    $q->where('country_id', $filters->countryId);
                }

                if ($filters->instructorStatus !== null) {
                    $q->where('instructor_status', $filters->instructorStatus->value);
                }
            });
        }

        if ($filters->subjectId !== null) {
            // Real taught subject (via lessons), not the free-text teacher_subjects string.
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('lessons')
                ->whereColumn('lessons.instructor_id', 'users.id')
                ->whereNull('lessons.deleted_at')
                ->where('lessons.subject_id', $filters->subjectId));
        }

        return $query;
    }

    private function scopedBookings(ReportingPeriod $period, ReportFilters $filters): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('bookings')
            ->whereNull('bookings.deleted_at')
            ->where('bookings.created_at', '>=', $period->startUtc)
            ->where('bookings.created_at', '<', $period->endUtcExclusive)
            ->whereIn('bookings.instructor_id', $this->instructors($filters)->select('users.id'));

        if ($filters->bookingType !== null) {
            $query->whereIn('bookings.booking_type_id', DB::table('booking_types')->where('key', $filters->bookingType->value)->select('id'));
        }

        return $query;
    }

    /** @return array<string, int> keyed by booking type key */
    private function bookingTypeCounts(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->scopedBookings($period, $filters)
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->selectRaw('booking_types.key as type_key, count(*) as aggregate')
            ->groupBy('booking_types.key')
            ->pluck('aggregate', 'type_key')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return array<string, int> keyed by LessonOutcome::value (finalized in period) */
    private function outcomeCounts(ReportingPeriod $period, ReportFilters $filters): array
    {
        $query = DB::table('lessons')
            ->whereNull('lessons.deleted_at')
            ->whereNotNull('lessons.outcome_finalized_at')
            ->where('lessons.outcome', '!=', 'pending')
            ->where('lessons.outcome_finalized_at', '>=', $period->startUtc)
            ->where('lessons.outcome_finalized_at', '<', $period->endUtcExclusive)
            ->whereIn('lessons.instructor_id', $this->instructors($filters)->select('users.id'));

        if ($filters->lessonOutcome !== null) {
            $query->where('lessons.outcome', $filters->lessonOutcome->value);
        }

        return $query
            ->selectRaw('lessons.outcome, count(*) as aggregate')
            ->groupBy('lessons.outcome')
            ->pluck('aggregate', 'outcome')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
