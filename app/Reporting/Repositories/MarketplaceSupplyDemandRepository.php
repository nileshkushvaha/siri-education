<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Enums\InstructorStatus;
use App\Reporting\DTOs\Marketplace\MarketplaceComparisonData;
use App\Reporting\DTOs\Marketplace\MarketplaceDemandData;
use App\Reporting\DTOs\Marketplace\MarketplaceSubjectGapRow;
use App\Reporting\DTOs\Marketplace\MarketplaceSupplyData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\RecurrenceClassifier;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only marketplace supply/demand aggregates. Shared
 * definitions are inherited, never redefined: instructor = user with
 * the `instructor` role and CURRENT `user_profiles.instructor_status`;
 * booking demand = bookings created in the period with
 * subject attribution via `lessons.subject_id`;
 * recurrence via RecurrenceClassifier with `unknown_historical` kept
 * separate. Supply figures are current-state; demand figures are
 * period events; comparisons pair compatible dimensions only. No
 * search events, profile views or waitlists exist in Version 1 —
 * nothing here infers them.
 */
final class MarketplaceSupplyDemandRepository
{
    // ── Supply (current-state) ────────────────────────────────────────────

    public function supply(ReportFilters $filters): MarketplaceSupplyData
    {
        $byStatus = $this->instructors($filters)
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->selectRaw("COALESCE(user_profiles.instructor_status, 'unknown') as status_key, count(*) as aggregate")
            ->groupBy('status_key')
            ->pluck('aggregate', 'status_key')
            ->map(fn ($v) => (int) $v)
            ->all();

        $statuses = array_fill_keys(array_map(fn (InstructorStatus $s) => $s->value, InstructorStatus::cases()), 0);
        foreach ($byStatus as $status => $count) {
            $statuses[$status] = $count;
        }

        $activeWithAvailability = (int) $this->activeInstructors($filters)
            ->whereExists(fn (Builder $q) => $q
                ->from('teacher_availability')
                ->whereColumn('teacher_availability.teacher_id', 'users.id')
                ->where('teacher_availability.is_active', true))
            ->count();

        $activeTotal = $statuses[InstructorStatus::Active->value];

        return new MarketplaceSupplyData(
            totalInstructors: array_sum($statuses),
            byStatus: $statuses,
            activeInstructors: $activeTotal,
            approvedInstructors: $statuses[InstructorStatus::Approved->value],
            onVacation: $statuses[InstructorStatus::Vacation->value],
            suspended: $statuses[InstructorStatus::Suspended->value],
            activeWithPublishedAvailability: $activeWithAvailability,
            activeWithoutPublishedAvailability: max(0, $activeTotal - $activeWithAvailability),
            bySubject: $this->activeInstructorsBySubject($filters),
            byCountry: $this->activeInstructorsByCountry($filters),
        );
    }

    // ── Demand (period events) ────────────────────────────────────────────

    public function demand(ReportingPeriod $period, ReportFilters $filters): MarketplaceDemandData
    {
        $totals = $this->bookings($period, $filters)
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->selectRaw('count(*) as total,
                count(distinct bookings.student_id) as students,
                SUM(booking_types.is_paid = 0) as demo_count,
                SUM(booking_types.is_paid = 1) as paid_count')
            ->first();

        $recurrence = $this->bookings($period, $filters)
            ->selectRaw(RecurrenceClassifier::caseExpression().' as bucket, count(*) as aggregate')
            ->groupBy('bucket')
            ->pluck('aggregate', 'bucket');

        $buckets = array_fill_keys(RecurrenceClassifier::buckets(), 0);
        foreach ($recurrence as $bucket => $count) {
            $buckets[$bucket] = (int) $count;
        }

        return new MarketplaceDemandData(
            bookingsInPeriod: (int) ($totals->total ?? 0),
            studentsWithBookings: (int) ($totals->students ?? 0),
            demoBookings: (int) ($totals->demo_count ?? 0),
            paidBookings: (int) ($totals->paid_count ?? 0),
            byRecurrence: $buckets,
            bySubject: $this->demandBySubject($period, $filters),
            byCountry: $this->demandByCountry($period, $filters),
            activeGoalDemandBySubject: $this->activeGoalDemandBySubject($filters),
            preferredSubjectInterest: $this->preferredSubjectInterest(),
            byWeekday: $this->demandByWeekday($period, $filters),
        );
    }

    // ── Comparison (compatible dimensions only) ───────────────────────────

    public function comparison(ReportingPeriod $period, ReportFilters $filters): MarketplaceComparisonData
    {
        $activeInstructors = (int) $this->activeInstructors($filters)->count();
        $bookings = (int) $this->bookings($period, $filters)->count();

        return new MarketplaceComparisonData(
            demandPerActiveInstructor: $activeInstructors > 0 ? round($bookings / $activeInstructors, 1) : null,
            subjectGaps: $this->subjectGaps($period, $filters),
            activeInstructorsWithoutBookings: (int) $this->activeInstructors($filters)
                ->whereNotExists(fn (Builder $q) => $q
                    ->from('bookings')
                    ->whereColumn('bookings.instructor_id', 'users.id')
                    ->whereNull('bookings.deleted_at')
                    ->where('bookings.created_at', '>=', $period->startUtc)
                    ->where('bookings.created_at', '<', $period->endUtcExclusive))
                ->count(),
            countriesWithDemandNoSupply: $this->countriesWithDemandNoSupply($period, $filters),
        );
    }

    // ── Internals — instructor supply ─────────────────────────────────────

    /** Same predicate as the Instructor Performance report: a user holding the `instructor` role. */
    private function instructors(ReportFilters $filters): Builder
    {
        $query = DB::table('users')
            ->whereExists(fn (Builder $q) => $q
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', 'App\Models\User')
                ->where('roles.name', 'instructor'));

        if ($filters->instructorId !== null) {
            $query->where('users.id', $filters->instructorId);
        }

        if ($filters->countryId !== null) {
            $query->whereExists(fn (Builder $q) => $q
                ->from('user_profiles')
                ->whereColumn('user_profiles.user_id', 'users.id')
                ->where('user_profiles.country_id', $filters->countryId));
        }

        if ($filters->subjectId !== null) {
            $query->whereExists(fn (Builder $q) => $q
                ->from('teacher_subjects')
                ->whereColumn('teacher_subjects.teacher_id', 'users.id')
                ->where('teacher_subjects.subject_id', $filters->subjectId));
        }

        return $query;
    }

    private function activeInstructors(ReportFilters $filters): Builder
    {
        return $this->instructors($filters)
            ->whereExists(fn (Builder $q) => $q
                ->from('user_profiles')
                ->whereColumn('user_profiles.user_id', 'users.id')
                ->where('user_profiles.instructor_status', InstructorStatus::Active->value));
    }

    /** @return list<LabeledCountRow> CURRENT subject assignment of active instructors — never historical supply. */
    private function activeInstructorsBySubject(ReportFilters $filters): array
    {
        return $this->activeInstructors($filters)
            ->join('teacher_subjects', 'teacher_subjects.teacher_id', '=', 'users.id')
            ->join('subjects', 'subjects.id', '=', 'teacher_subjects.subject_id')
            ->selectRaw('subjects.name as label, count(distinct users.id) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> active instructors per current profile country. */
    private function activeInstructorsByCountry(ReportFilters $filters): array
    {
        return $this->activeInstructors($filters)
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->leftJoin('countries', 'countries.id', '=', 'user_profiles.country_id')
            ->selectRaw("COALESCE(countries.name, 'Unknown') as label, count(*) as aggregate")
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    // ── Internals — booking demand ────────────────────────────────────────

    private function bookings(ReportingPeriod $period, ReportFilters $filters): Builder
    {
        $query = DB::table('bookings')
            ->whereNull('bookings.deleted_at')
            ->where('bookings.created_at', '>=', $period->startUtc)
            ->where('bookings.created_at', '<', $period->endUtcExclusive);

        if ($filters->instructorId !== null) {
            $query->where('bookings.instructor_id', $filters->instructorId);
        }

        if ($filters->countryId !== null) {
            $query->whereExists(fn (Builder $q) => $q
                ->from('user_profiles')
                ->whereColumn('user_profiles.user_id', 'bookings.student_id')
                ->where('user_profiles.country_id', $filters->countryId));
        }

        if ($filters->subjectId !== null) {
            $query->whereExists(fn (Builder $q) => $q
                ->from('lessons')
                ->whereColumn('lessons.booking_id', 'bookings.id')
                ->where('lessons.subject_id', $filters->subjectId));
        }

        return $query;
    }

    /** @return list<LabeledCountRow> Subject basis: via the associated lesson — bookings carry no subject FK. */
    private function demandBySubject(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->bookings($period, $filters)
            ->join('lessons', 'lessons.booking_id', '=', 'bookings.id')
            ->join('subjects', 'subjects.id', '=', 'lessons.subject_id')
            ->selectRaw('subjects.name as label, count(distinct bookings.id) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> bookings per student CURRENT profile country (labelled current-profile attribute). */
    private function demandByCountry(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->bookings($period, $filters)
            ->join('user_profiles', 'user_profiles.user_id', '=', 'bookings.student_id')
            ->leftJoin('countries', 'countries.id', '=', 'user_profiles.country_id')
            ->selectRaw("COALESCE(countries.name, 'Unknown') as label, count(*) as aggregate")
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> currently Active learning goals per subject — an interest signal, not bookings. */
    private function activeGoalDemandBySubject(ReportFilters $filters): array
    {
        $query = DB::table('student_learning_goals')
            ->whereNull('student_learning_goals.deleted_at')
            ->where('student_learning_goals.status', 'active')
            ->join('subjects', 'subjects.id', '=', 'student_learning_goals.subject_id');

        if ($filters->subjectId !== null) {
            $query->where('student_learning_goals.subject_id', $filters->subjectId);
        }

        return $query
            ->selectRaw('subjects.name as label, count(*) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> Students per SELF-SELECTED preferred subject. */
    private function preferredSubjectInterest(): array
    {
        return DB::table('student_preferred_subjects')
            ->join('subjects', 'subjects.id', '=', 'student_preferred_subjects.subject_id')
            ->selectRaw('subjects.name as label, count(distinct student_preferred_subjects.user_id) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return array<string, int> weekday name => bookings starting that weekday, in the reporting timezone, zero-filled Mon–Sun. */
    private function demandByWeekday(ReportingPeriod $period, ReportFilters $filters): array
    {
        $offset = $period->start->format('P');

        $rows = $this->bookings($period, $filters)
            ->selectRaw('DAYNAME(CONVERT_TZ(bookings.starts_at, ?, ?)) as day, count(*) as aggregate', ['+00:00', $offset])
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $result = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], 0);
        foreach ($rows as $day => $count) {
            if ($day !== null) {
                $result[$day] = (int) $count;
            }
        }

        return $result;
    }

    // ── Internals — comparison ────────────────────────────────────────────

    /** @return list<MarketplaceSubjectGapRow> subjects where demand or active supply is zero — bounded, factual, no score. */
    private function subjectGaps(ReportingPeriod $period, ReportFilters $filters): array
    {
        $demand = collect($this->demandBySubject($period, $filters))->keyBy('label');
        $supply = collect($this->activeInstructorsBySubject($filters))->keyBy('label');

        return $demand->keys()->merge($supply->keys())->unique()
            ->map(fn (string $label) => new MarketplaceSubjectGapRow(
                subjectLabel: $label,
                bookingsInPeriod: (int) ($demand[$label]->count ?? 0),
                activeInstructors: (int) ($supply[$label]->count ?? 0),
            ))
            ->filter(fn (MarketplaceSubjectGapRow $row) => $row->bookingsInPeriod === 0 || $row->activeInstructors === 0)
            ->sortByDesc(fn (MarketplaceSubjectGapRow $row) => $row->bookingsInPeriod)
            ->take(25)
            ->values()
            ->all();
    }

    /** @return list<string> countries with period booking demand and zero active instructors in that country. */
    private function countriesWithDemandNoSupply(ReportingPeriod $period, ReportFilters $filters): array
    {
        $demandCountries = collect($this->demandByCountry($period, $filters))->pluck('label')->reject(fn ($l) => $l === 'Unknown');
        $supplyCountries = collect($this->activeInstructorsByCountry($filters))->keyBy('label');

        return $demandCountries
            ->filter(fn (string $country) => (int) ($supplyCountries[$country]->count ?? 0) === 0)
            ->values()
            ->all();
    }
}
