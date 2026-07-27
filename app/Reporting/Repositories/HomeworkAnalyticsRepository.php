<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Homework\Enums\HomeworkStatus;
use App\Reporting\DTOs\Learning\HomeworkAnalyticsData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only homework aggregates. Overdue reuses the exact
 * domain predicate (status Pending AND due_at past — see
 * HomeworkAssignment::scopeOverdue) computed in SQL, never row-by-row.
 * Grading has no stored timestamp, so graded figures are as-of-now
 * only; rate denominators are restricted to assignments whose due date
 * has already elapsed, and both rates return null (never 0%) at zero
 * denominator. `submission_text`, `feedback` and `grade` are never
 * selected by any method in this class.
 */
final class HomeworkAnalyticsRepository
{
    public function summary(ReportingPeriod $period, ReportFilters $filters): HomeworkAnalyticsData
    {
        $byStatus = $this->homework($filters)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Observation-complete denominator: due inside the period AND already elapsed.
        $dueElapsed = $this->homework($filters)
            ->where('due_at', '>=', $period->startUtc)
            ->where('due_at', '<', $period->endUtcExclusive)
            ->where('due_at', '<=', now('UTC'))
            ->selectRaw('count(*) as total,
                SUM(submitted_at is not null) as submitted_any_time,
                SUM(submitted_at is not null and submitted_at <= due_at) as submitted_on_time')
            ->first();

        $denominator = (int) ($dueElapsed->total ?? 0);

        return new HomeworkAnalyticsData(
            assignedInPeriod: $this->countInPeriod('created_at', $period, $filters),
            submittedInPeriod: $this->countInPeriod('submitted_at', $period, $filters),
            submittedLateInPeriod: (int) $this->homework($filters)
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '>=', $period->startUtc)
                ->where('submitted_at', '<', $period->endUtcExclusive)
                ->whereColumn('submitted_at', '>', 'due_at')
                ->count(),
            currentlyOverdue: (int) $this->homework($filters)
                ->where('status', HomeworkStatus::Pending->value)
                ->where('due_at', '<', now('UTC'))
                ->count(),
            dueElapsedInPeriod: $denominator,
            submissionRate: $denominator > 0 ? round(((int) $dueElapsed->submitted_any_time / $denominator) * 100, 1) : null,
            onTimeSubmissionRate: $denominator > 0 ? round(((int) $dueElapsed->submitted_on_time / $denominator) * 100, 1) : null,
            currentByStatus: $byStatus,
            gradedCurrent: (int) ($byStatus[HomeworkStatus::Graded->value] ?? 0),
            linkedToBookings: (int) $this->homework($filters)->whereNotNull('booking_id')->count(),
            withoutBookingLink: (int) $this->homework($filters)->whereNull('booking_id')->count(),
            byTeacher: $this->byTeacher($period, $filters),
            bySubjectText: $this->bySubjectText($period, $filters),
        );
    }

    /**
     * Daily period-event trend on one timestamp column, fixed-offset
     * bucketing, zero-filled.
     *
     * @return array<string, int>
     */
    public function trend(string $column, ReportingPeriod $period, ReportFilters $filters): array
    {
        $offset = $period->start->format('P');

        $rows = $this->homework($filters)
            ->whereNotNull($column)
            ->where($column, '>=', $period->startUtc)
            ->where($column, '<', $period->endUtcExclusive)
            ->selectRaw("DATE(CONVERT_TZ(`{$column}`, ?, ?)) as day, count(*) as aggregate", ['+00:00', $offset])
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

    /**
     * Bounded attention table: currently-overdue assignments and
     * submitted assignments awaiting grading, oldest due first. Raw
     * rows only — the service masks identity and builds DTOs. Private
     * content columns are structurally never selected.
     *
     * @return object{total: int, rows: Collection<int, object>}
     */
    public function attentionRows(ReportFilters $filters, int $page, int $perPage): object
    {
        $base = $this->homework($filters)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $overdue) => $overdue
                    ->where('homework_assignments.status', HomeworkStatus::Pending->value)
                    ->where('homework_assignments.due_at', '<', now('UTC')))
                ->orWhere('homework_assignments.status', HomeworkStatus::Submitted->value));

        $total = (clone $base)->count();

        $rows = $base
            ->join('users as students', 'students.id', '=', 'homework_assignments.student_id')
            ->join('users as teachers', 'teachers.id', '=', 'homework_assignments.teacher_id')
            ->orderBy('homework_assignments.due_at')
            ->orderBy('homework_assignments.id')
            ->forPage($page, $perPage)
            ->get([
                'homework_assignments.id',
                'homework_assignments.subject',
                'homework_assignments.status',
                'homework_assignments.created_at',
                'homework_assignments.due_at',
                'homework_assignments.submitted_at',
                'students.first_name as student_first_name',
                'students.last_name as student_last_name',
                'teachers.first_name as teacher_first_name',
                'teachers.last_name as teacher_last_name',
                DB::raw('GREATEST(0, DATEDIFF(UTC_TIMESTAMP(), homework_assignments.due_at)) as age_days'),
            ]);

        return (object) ['total' => $total, 'rows' => $rows];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function homework(ReportFilters $filters): Builder
    {
        $query = DB::table('homework_assignments')->whereNull('homework_assignments.deleted_at');

        if ($filters->instructorId !== null) {
            $query->where('homework_assignments.teacher_id', $filters->instructorId);
        }

        if ($filters->studentId !== null) {
            $query->where('homework_assignments.student_id', $filters->studentId);
        }

        if ($filters->homeworkStatus !== null) {
            $query->where('homework_assignments.status', $filters->homeworkStatus->value);
        }

        return $query;
    }

    private function countInPeriod(string $column, ReportingPeriod $period, ReportFilters $filters): int
    {
        return (int) $this->homework($filters)
            ->whereNotNull($column)
            ->where($column, '>=', $period->startUtc)
            ->where($column, '<', $period->endUtcExclusive)
            ->count();
    }

    /** @return list<LabeledCountRow> assignments created in period per instructor (assignment frequency). */
    private function byTeacher(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->homework($filters)
            ->join('users', 'users.id', '=', 'homework_assignments.teacher_id')
            ->where('homework_assignments.created_at', '>=', $period->startUtc)
            ->where('homework_assignments.created_at', '<', $period->endUtcExclusive)
            ->selectRaw("CONCAT(users.first_name, ' ', users.last_name) as label, count(*) as aggregate")
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> assignment-declared subject text (no subject FK exists on homework). */
    private function bySubjectText(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->homework($filters)
            ->where('homework_assignments.created_at', '>=', $period->startUtc)
            ->where('homework_assignments.created_at', '<', $period->endUtcExclusive)
            ->selectRaw('homework_assignments.subject as label, count(*) as aggregate')
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }
}
