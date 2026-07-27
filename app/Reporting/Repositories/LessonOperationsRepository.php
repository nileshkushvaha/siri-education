<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\DTOs\Operations\LessonOutcomeSummaryData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only aggregate queries over `lessons`. Date basis:
 * `starts_at` for "scheduled" (scheduled-activity view — what's on the
 * calendar in this period, regardless of outcome yet); `outcome_finalized_at`
 * for every finalized-outcome count (business-event view — never
 * `LessonStatus::Completed` alone, which can precede finalization).
 * `disputed` and `unfinalizedPastDue` are current-state snapshots, not
 * period-scoped business events, since they answer "what needs
 * attention right now", the same framing `App\Quality`'s own alert
 * queues use.
 */
final class LessonOperationsRepository
{
    public function summary(ReportingPeriod $period, ReportFilters $filters): LessonOutcomeSummaryData
    {
        return new LessonOutcomeSummaryData(
            scheduled: $this->scheduledCount($period, $filters),
            finalized: $this->finalizedCount($period, $filters),
            byOutcome: $this->countByOutcome($period, $filters),
            disputed: $this->disputedCount($filters),
            unfinalizedPastDue: $this->unfinalizedPastDueCount($period, $filters),
        );
    }

    public function scheduledCount(ReportingPeriod $period, ReportFilters $filters): int
    {
        return $this->scopedByStartsAt($period, $filters)->count();
    }

    public function finalizedCount(ReportingPeriod $period, ReportFilters $filters): int
    {
        return $this->scopedByFinalization($period, $filters)->count();
    }

    /** @return array<string, int> keyed by LessonOutcome::value, finalized lessons only. */
    public function countByOutcome(ReportingPeriod $period, ReportFilters $filters): array
    {
        $rows = $this->scopedByFinalization($period, $filters)
            ->selectRaw('outcome, count(*) as aggregate')
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome');

        $result = array_fill_keys(array_map(fn (LessonOutcome $o) => $o->value, LessonOutcome::cases()), 0);

        foreach ($rows as $outcome => $count) {
            $key = is_string($outcome) ? $outcome : $outcome->value;
            $result[$key] = (int) $count;
        }

        unset($result[LessonOutcome::Pending->value]);

        return $result;
    }

    /** Lessons currently under active dispute (LessonStatus::Disputed) — a snapshot of right now, not period-scoped. */
    public function disputedCount(ReportFilters $filters): int
    {
        $query = Lesson::query()->where('status', LessonStatus::Disputed);
        $this->applyLessonScopedFilters($query, $filters);

        return $query->count();
    }

    /** Lessons whose scheduled end has already passed but whose outcome was never finalized — a "stuck" operational indicator. */
    public function unfinalizedPastDueCount(ReportingPeriod $period, ReportFilters $filters): int
    {
        $query = Lesson::query()
            ->where('starts_at', '>=', $period->startUtc)
            ->where('starts_at', '<', $period->endUtcExclusive)
            ->where('ends_at', '<', Carbon::now())
            ->where('outcome', LessonOutcome::Pending);
        $this->applyLessonScopedFilters($query, $filters);

        return $query->count();
    }

    /** @return list<LabeledCountRow> top 10 subjects by finalized-lesson count. */
    public function bySubject(ReportingPeriod $period, ReportFilters $filters, int $limit = 10): array
    {
        return $this->scopedByFinalization($period, $filters)
            ->join('subjects', 'subjects.id', '=', 'lessons.subject_id')
            ->selectRaw('subjects.name as label, count(*) as aggregate')
            ->groupBy('subjects.name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> top 10 instructors by finalized-lesson count. */
    public function byInstructor(ReportingPeriod $period, ReportFilters $filters, int $limit = 10): array
    {
        return $this->scopedByFinalization($period, $filters)
            ->join('users', 'users.id', '=', 'lessons.instructor_id')
            ->selectRaw('users.first_name, users.last_name, count(*) as aggregate')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow(trim("{$row->first_name} {$row->last_name}"), (int) $row->aggregate))
            ->all();
    }

    /** Bounded, paginated — every lesson scheduled in the period, newest first. */
    public function paginatedLessonsInPeriod(ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->scopedByStartsAt($period, $filters)
            ->with(['booking.type', 'booking.meeting', 'student', 'instructor', 'subject'])
            ->orderByDesc('starts_at')
            ->paginate($perPage);
    }

    /** Bounded, paginated — lessons needing operational attention: finalized no-show/technical-issue outcomes in the period, plus any lesson currently under active dispute. */
    public function paginatedNoShowAndTechnicalIssueLessons(ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $actionableOutcomes = [LessonOutcome::StudentNoShow, LessonOutcome::InstructorNoShow, LessonOutcome::BothAbsent, LessonOutcome::TechnicalIssue];

        $query = Lesson::query()
            ->where('starts_at', '>=', $period->startUtc)
            ->where('starts_at', '<', $period->endUtcExclusive)
            ->where(fn (Builder $q) => $q->whereIn('outcome', $actionableOutcomes)->orWhere('status', LessonStatus::Disputed));

        $this->applyLessonScopedFilters($query, $filters);

        return $query
            ->with(['booking', 'student', 'instructor', 'subject'])
            ->orderByDesc('starts_at')
            ->paginate($perPage);
    }

    private function scopedByStartsAt(ReportingPeriod $period, ReportFilters $filters): Builder
    {
        $query = Lesson::query()
            ->where('starts_at', '>=', $period->startUtc)
            ->where('starts_at', '<', $period->endUtcExclusive);

        return $this->applyLessonScopedFilters($query, $filters);
    }

    private function scopedByFinalization(ReportingPeriod $period, ReportFilters $filters): Builder
    {
        $query = Lesson::query()
            ->whereNotNull('outcome_finalized_at')
            ->where('outcome', '!=', LessonOutcome::Pending)
            ->where('outcome_finalized_at', '>=', $period->startUtc)
            ->where('outcome_finalized_at', '<', $period->endUtcExclusive);

        return $this->applyLessonScopedFilters($query, $filters);
    }

    private function applyLessonScopedFilters(Builder $query, ReportFilters $filters): Builder
    {
        if ($filters->instructorId !== null) {
            $query->where('lessons.instructor_id', $filters->instructorId);
        }

        if ($filters->studentId !== null) {
            $query->where('lessons.student_id', $filters->studentId);
        }

        if ($filters->lessonStatus !== null) {
            $query->where('lessons.status', $filters->lessonStatus);
        }

        if ($filters->lessonOutcome !== null) {
            $query->where('lessons.outcome', $filters->lessonOutcome);
        }

        if ($filters->subjectId !== null) {
            $query->where('lessons.subject_id', $filters->subjectId);
        }

        if ($filters->countryId !== null) {
            $query->whereHas(
                'student',
                fn (Builder $q) => $q->whereHas('profile', fn (Builder $p) => $p->where('country_id', $filters->countryId)),
            );
        }

        if ($filters->bookingType !== null) {
            $query->whereHas(
                'booking',
                fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('key', $filters->bookingType->value)),
            );
        }

        return $query;
    }
}
