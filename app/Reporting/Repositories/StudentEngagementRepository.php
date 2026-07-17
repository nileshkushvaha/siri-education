<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Models\User;
use App\Reporting\DTOs\Engagement\StudentEngagementSummaryData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\RecurrenceClassifier;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries for the Student Engagement report
 * (Phase 18D). "Student" = a user holding the `student` role — the one
 * authoritative membership definition, applied identically everywhere.
 * Account status is the CURRENT `user_profiles.student_status`;
 * engagement is period-scoped booking/lesson events. Every count is a
 * database aggregate; the review table is the only row-returning query
 * and is paginated with constant query count.
 */
final class StudentEngagementRepository
{
    public function summary(ReportingPeriod $period, ReportFilters $filters): StudentEngagementSummaryData
    {
        return new StudentEngagementSummaryData(
            totalStudents: $this->students($filters)->count(),
            newInPeriod: $this->students($filters)
                ->whereBetween('users.created_at', [$period->startUtc, $period->endUtcExclusive])
                ->where('users.created_at', '<', $period->endUtcExclusive)
                ->count(),
            verifiedTotal: $this->students($filters)->whereNotNull('users.email_verified_at')->count(),
            verifiedInPeriod: $this->students($filters)
                ->where('users.email_verified_at', '>=', $period->startUtc)
                ->where('users.email_verified_at', '<', $period->endUtcExclusive)
                ->count(),
            byAccountStatus: $this->byAccountStatus($filters),
            engagedInPeriod: $this->students($filters)
                ->where(fn (Builder $q) => $q
                    ->whereExists($this->bookingInPeriodExists($period))
                    ->orWhereExists($this->completedLessonInPeriodExists($period)))
                ->count(),
            withBookingsInPeriod: $this->students($filters)->whereExists($this->bookingInPeriodExists($period))->count(),
            withCompletedLessonsInPeriod: $this->students($filters)->whereExists($this->completedLessonInPeriodExists($period))->count(),
            withActiveLearningPlans: $this->students($filters)
                ->whereExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                    ->from('student_learning_plans')
                    ->whereColumn('student_learning_plans.student_user_id', 'users.id')
                    ->where('student_learning_plans.status', LearningPlanStatus::Active->value)
                    ->whereNull('student_learning_plans.deleted_at'))
                ->count(),
            recurringParticipation: $this->recurringParticipation($period, $filters),
            withActiveLearningGoals: $this->students($filters)
                ->whereExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                    ->from('student_learning_goals')
                    ->whereColumn('student_learning_goals.user_id', 'users.id')
                    ->where('student_learning_goals.status', LearningGoalStatus::Active->value)
                    ->whereNull('student_learning_goals.deleted_at'))
                ->count(),
            withHomeworkActivityInPeriod: $this->students($filters)
                ->whereExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                    ->from('homework_assignments')
                    ->whereColumn('homework_assignments.student_id', 'users.id')
                    ->where('homework_assignments.submitted_at', '>=', $period->startUtc)
                    ->where('homework_assignments.submitted_at', '<', $period->endUtcExclusive)
                    ->whereNull('homework_assignments.deleted_at'))
                ->count(),
            withReviewsSubmittedInPeriod: $this->students($filters)
                ->whereExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                    ->from('lesson_reviews')
                    ->whereColumn('lesson_reviews.student_id', 'users.id')
                    ->where('lesson_reviews.submitted_at', '>=', $period->startUtc)
                    ->where('lesson_reviews.submitted_at', '<', $period->endUtcExclusive))
                ->count(),
            withoutRecentLearningActivity: $this->withoutRecentLearningActivity($period, $filters),
            lifetimeBookingBuckets: $this->lifetimeBookingBuckets($filters),
        );
    }

    /** §6.2/6.3 — deterministic rule: non-suspended/non-archived student accounts with zero qualifying learning activity in the period. */
    public function withoutRecentLearningActivity(ReportingPeriod $period, ReportFilters $filters): int
    {
        return $this->students($filters)
            ->whereHas('profile', fn (Builder $q) => $q
                ->where(fn (Builder $p) => $p
                    ->whereNull('student_status')
                    ->orWhereNotIn('student_status', [StudentStatus::Suspended->value, StudentStatus::Archived->value])))
            ->whereNotExists($this->bookingInPeriodExists($period))
            ->whereNotExists($this->completedLessonInPeriodExists($period))
            ->count();
    }

    /** @return array<string, int> keyed by StudentStatus::value; 'unknown' for profiles never backfilled. */
    public function byAccountStatus(ReportFilters $filters): array
    {
        $rows = $this->students($filters)
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->selectRaw("COALESCE(user_profiles.student_status, 'unknown') as status_key, count(*) as aggregate")
            ->groupBy('status_key')
            ->pluck('aggregate', 'status_key');

        $result = array_fill_keys(array_map(fn (StudentStatus $s) => $s->value, StudentStatus::cases()), 0);

        foreach ($rows as $status => $count) {
            $result[$status] = (int) $count;
        }

        return $result;
    }

    /** @return array<string, int> RecurrenceClassifier bucket => distinct students with such a booking created in the period. */
    public function recurringParticipation(ReportingPeriod $period, ReportFilters $filters): array
    {
        $rows = DB::table('bookings')
            ->whereNull('bookings.deleted_at')
            ->where('bookings.created_at', '>=', $period->startUtc)
            ->where('bookings.created_at', '<', $period->endUtcExclusive)
            ->whereIn('bookings.student_id', $this->students($filters)->select('users.id'))
            ->selectRaw(RecurrenceClassifier::caseExpression().' as bucket, count(distinct student_id) as aggregate')
            ->groupBy('bucket')
            ->pluck('aggregate', 'bucket');

        $result = array_fill_keys(RecurrenceClassifier::buckets(), 0);

        foreach ($rows as $bucket => $count) {
            $result[$bucket] = (int) $count;
        }

        return $result;
    }

    /** @return array<string, int> lifetime (not period-scoped) booking-count distribution. */
    public function lifetimeBookingBuckets(ReportFilters $filters): array
    {
        $rows = $this->students($filters)
            ->leftJoinSub(
                DB::table('bookings')
                    ->whereNull('deleted_at')
                    ->selectRaw('student_id, count(*) as booking_count')
                    ->groupBy('student_id'),
                'lifetime',
                'lifetime.student_id',
                '=',
                'users.id',
            )
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN COALESCE(lifetime.booking_count, 0) = 0 THEN '0'
                    WHEN lifetime.booking_count = 1 THEN '1'
                    WHEN lifetime.booking_count BETWEEN 2 AND 5 THEN '2-5'
                    WHEN lifetime.booking_count BETWEEN 6 AND 10 THEN '6-10'
                    ELSE '11+'
                END as bucket, count(*) as aggregate
                SQL)
            ->groupBy('bucket')
            ->pluck('aggregate', 'bucket');

        $result = array_fill_keys(['0', '1', '2-5', '6-10', '11+'], 0);

        foreach ($rows as $bucket => $count) {
            $result[$bucket] = (int) $count;
        }

        return $result;
    }

    /** @return list<LabeledCountRow> students by CURRENT profile country (current-state attribute — labeled so in the UI). */
    public function byCountry(ReportFilters $filters, int $limit = 10): array
    {
        return $this->students($filters)
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->join('countries', 'countries.id', '=', 'user_profiles.country_id')
            ->selectRaw('countries.name as label, count(*) as aggregate')
            ->groupBy('countries.name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> students by CURRENT academic level (current-state attribute). */
    public function byAcademicLevel(ReportFilters $filters, int $limit = 10): array
    {
        return $this->students($filters)
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->join('academic_levels', 'academic_levels.id', '=', 'user_profiles.student_academic_level_id')
            ->selectRaw('academic_levels.name as label, count(*) as aggregate')
            ->groupBy('academic_levels.name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> students by SELF-SELECTED preferred subject (a preference, never labeled as a learned subject). */
    public function byPreferredSubject(ReportFilters $filters, int $limit = 10): array
    {
        return $this->students($filters)
            ->join('student_preferred_subjects', 'student_preferred_subjects.user_id', '=', 'users.id')
            ->join('subjects', 'subjects.id', '=', 'student_preferred_subjects.subject_id')
            ->selectRaw('subjects.name as label, count(distinct users.id) as aggregate')
            ->groupBy('subjects.name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> distinct students per ACTUALLY BOOKED subject — lessons scheduled in the period (historical-event attribute). */
    public function byBookedSubject(ReportingPeriod $period, ReportFilters $filters, int $limit = 10): array
    {
        return DB::table('lessons')
            ->join('subjects', 'subjects.id', '=', 'lessons.subject_id')
            ->whereNull('lessons.deleted_at')
            ->where('lessons.starts_at', '>=', $period->startUtc)
            ->where('lessons.starts_at', '<', $period->endUtcExclusive)
            ->whereIn('lessons.student_id', $this->students($filters)->select('users.id'))
            ->selectRaw('subjects.name as label, count(distinct lessons.student_id) as aggregate')
            ->groupBy('subjects.name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /**
     * @return array<string, int> new student registrations per day (Y-m-d in
     *                            reporting timezone), zero-filled, bounded by the period. Bucketing
     *                            uses the timezone's numeric offset at period start (CONVERT_TZ with
     *                            named zones requires MySQL tz tables, which are not guaranteed) —
     *                            exact for fixed-offset zones like the platform default Asia/Kolkata;
     *                            for DST zones, rows within an hour of midnight on a transition day
     *                            may land in the adjacent bucket. Documented metric limitation.
     */
    public function registrationTrend(ReportingPeriod $period, ReportFilters $filters): array
    {
        $offset = $period->start->format('P'); // e.g. '+05:30'

        $rows = $this->students($filters)
            ->where('users.created_at', '>=', $period->startUtc)
            ->where('users.created_at', '<', $period->endUtcExclusive)
            ->selectRaw('DATE(CONVERT_TZ(users.created_at, ?, ?)) as day, count(*) as aggregate', ['+00:00', $offset])
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $result = [];
        $cursor = $period->start;

        while ($cursor->lt($period->end)) {
            $key = $cursor->toDateString();
            $result[$key] = (int) ($rows[$key] ?? 0);
            $cursor = $cursor->addDay();
        }

        return $result;
    }

    /** Bounded, paginated review table — constant query count via aggregate subselects, ordered by in-period engagement. */
    public function paginatedEngagementRows(ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->students($filters)
            ->with(['profile.country'])
            ->select('users.*')
            ->selectSub($this->bookingCountSub(), 'lifetime_booking_count')
            ->selectSub($this->bookingCountSub($period), 'bookings_in_period')
            ->selectSub($this->completedLessonCountSub($period), 'completed_lessons_in_period')
            ->selectSub(
                DB::table('student_learning_plans')
                    ->whereColumn('student_user_id', 'users.id')
                    ->where('status', LearningPlanStatus::Active->value)
                    ->whereNull('deleted_at')
                    ->selectRaw('count(*)'),
                'active_learning_plan_count',
            )
            ->selectSub(
                DB::table('bookings')
                    ->whereColumn('student_id', 'users.id')
                    ->whereNull('deleted_at')
                    ->where('created_at', '>=', $period->startUtc)
                    ->where('created_at', '<', $period->endUtcExclusive)
                    ->selectRaw('max(created_at)'),
                'last_booking_in_period_at',
            )
            ->orderByDesc('bookings_in_period')
            ->orderBy('users.id')
            ->paginate($perPage);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** The single authoritative "student" base: users holding the student role, with the shared report filters applied. */
    private function students(ReportFilters $filters): Builder
    {
        $query = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'student'));

        if ($filters->studentId !== null) {
            $query->whereKey($filters->studentId);
        }

        if ($filters->countryId !== null || $filters->studentStatus !== null || $filters->educationLevelId !== null) {
            $query->whereHas('profile', function (Builder $q) use ($filters): void {
                if ($filters->countryId !== null) {
                    $q->where('country_id', $filters->countryId);
                }

                if ($filters->studentStatus !== null) {
                    $q->where('student_status', $filters->studentStatus->value);
                }

                if ($filters->educationLevelId !== null) {
                    $q->where('student_academic_level_id', $filters->educationLevelId);
                }
            });
        }

        return $query;
    }

    private function bookingInPeriodExists(ReportingPeriod $period): \Closure
    {
        return fn (QueryBuilder $q) => $q->select(DB::raw(1))
            ->from('bookings')
            ->whereColumn('bookings.student_id', 'users.id')
            ->whereNull('bookings.deleted_at')
            ->where('bookings.created_at', '>=', $period->startUtc)
            ->where('bookings.created_at', '<', $period->endUtcExclusive);
    }

    private function completedLessonInPeriodExists(ReportingPeriod $period): \Closure
    {
        return fn (QueryBuilder $q) => $q->select(DB::raw(1))
            ->from('lessons')
            ->whereColumn('lessons.student_id', 'users.id')
            ->whereNull('lessons.deleted_at')
            ->where('lessons.outcome', 'completed')
            ->whereNotNull('lessons.outcome_finalized_at')
            ->where('lessons.outcome_finalized_at', '>=', $period->startUtc)
            ->where('lessons.outcome_finalized_at', '<', $period->endUtcExclusive);
    }

    private function bookingCountSub(?ReportingPeriod $period = null): QueryBuilder
    {
        $sub = DB::table('bookings')
            ->whereColumn('student_id', 'users.id')
            ->whereNull('deleted_at')
            ->selectRaw('count(*)');

        if ($period !== null) {
            $sub->where('created_at', '>=', $period->startUtc)->where('created_at', '<', $period->endUtcExclusive);
        }

        return $sub;
    }

    private function completedLessonCountSub(ReportingPeriod $period): QueryBuilder
    {
        return DB::table('lessons')
            ->whereColumn('student_id', 'users.id')
            ->whereNull('deleted_at')
            ->where('outcome', 'completed')
            ->whereNotNull('outcome_finalized_at')
            ->where('outcome_finalized_at', '>=', $period->startUtc)
            ->where('outcome_finalized_at', '<', $period->endUtcExclusive)
            ->selectRaw('count(*)');
    }
}
