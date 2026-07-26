<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\DTOs\Instructor\InstructorStudentSummaryData;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\StudentLearningPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read-only instructor student roster (Phase 23N). The instructor-student
 * relationship is never a new table — it is derived entirely from Lesson,
 * the same authoritative source Phase 23K's lesson management already
 * relies on. Three bounded queries per page load: the grouped Lesson
 * aggregate (paginated), a student hydration lookup, and a learning-plan
 * status lookup — never a query per row.
 */
final class InstructorStudentService
{
    /** Cheap ownership check for the detail page — a real teaching relationship exists, regardless of lesson status. */
    public function hasRelationship(int $instructorId, int $studentId): bool
    {
        return Lesson::query()->forInstructor($instructorId)->forStudent($studentId)->exists();
    }

    /** Distinct students this instructor has ever had a lesson with — bounded aggregate, never a materialized list. */
    public function totalCount(int $instructorId): int
    {
        return Lesson::query()->forInstructor($instructorId)->distinct('student_id')->count('student_id');
    }

    /**
     * Students in the roster with no currently scheduled/live future
     * lesson (Phase 23P) — a point-in-time fact, never period-scoped
     * and never an "at risk"/retention judgment. Two bounded aggregate
     * queries, independent of roster size.
     */
    public function withoutUpcomingLessonCount(int $instructorId): int
    {
        $total = $this->totalCount($instructorId);

        $withUpcoming = Lesson::query()
            ->forInstructor($instructorId)
            ->open()
            ->where('starts_at', '>=', now())
            ->distinct('student_id')
            ->count('student_id');

        return max(0, $total - $withUpcoming);
    }

    /** Distinct students with a lesson starting inside [$periodStartUtc, $periodEndUtcExclusive) — a null start means "all time". */
    public function activeCount(int $instructorId, ?CarbonImmutable $periodStartUtc, CarbonImmutable $periodEndUtcExclusive): int
    {
        return Lesson::query()
            ->forInstructor($instructorId)
            ->when($periodStartUtc !== null, fn ($query) => $query->where('starts_at', '>=', $periodStartUtc))
            ->where('starts_at', '<', $periodEndUtcExclusive)
            ->distinct('student_id')
            ->count('student_id');
    }

    /** Students whose earliest completed lesson with this instructor falls inside the period — a single grouped aggregate query. A null start means "all time". */
    public function newCount(int $instructorId, ?CarbonImmutable $periodStartUtc, CarbonImmutable $periodEndUtcExclusive): int
    {
        return Lesson::query()
            ->forInstructor($instructorId)
            ->where('status', LessonStatus::Completed)
            ->selectRaw('student_id, MIN(starts_at) as first_completed_at')
            ->groupBy('student_id')
            ->havingRaw(
                $periodStartUtc !== null ? 'MIN(starts_at) >= ? AND MIN(starts_at) < ?' : 'MIN(starts_at) < ?',
                $periodStartUtc !== null ? [$periodStartUtc, $periodEndUtcExclusive] : [$periodEndUtcExclusive],
            )
            ->get()
            ->count();
    }

    public function paginatedForInstructor(int $instructorId, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = Lesson::query()
            ->forInstructor($instructorId)
            ->selectRaw(
                'student_id,
                 COUNT(*) as lessons_count,
                 SUM(status = ?) as completed_lessons,
                 SUM(status IN (?, ?) AND starts_at >= ?) as upcoming_lessons_count,
                 MAX(CASE WHEN starts_at < ? THEN starts_at END) as last_lesson_at,
                 MIN(CASE WHEN status IN (?, ?) AND starts_at >= ? THEN starts_at END) as next_lesson_at',
                [
                    LessonStatus::Completed->value,
                    LessonStatus::Scheduled->value, LessonStatus::Live->value, now(),
                    now(),
                    LessonStatus::Scheduled->value, LessonStatus::Live->value, now(),
                ],
            )
            ->groupBy('student_id')
            ->orderByRaw('COALESCE(next_lesson_at, last_lesson_at) DESC')
            ->paginate($perPage);

        $paginator->setCollection($this->hydrate($instructorId, $paginator->getCollection()));

        return $paginator;
    }

    public function summaryFor(int $instructorId, int $studentId): ?InstructorStudentSummaryData
    {
        $row = Lesson::query()
            ->forInstructor($instructorId)
            ->forStudent($studentId)
            ->selectRaw(
                'student_id,
                 COUNT(*) as lessons_count,
                 SUM(status = ?) as completed_lessons,
                 SUM(status IN (?, ?) AND starts_at >= ?) as upcoming_lessons_count,
                 MAX(CASE WHEN starts_at < ? THEN starts_at END) as last_lesson_at,
                 MIN(CASE WHEN status IN (?, ?) AND starts_at >= ? THEN starts_at END) as next_lesson_at',
                [
                    LessonStatus::Completed->value,
                    LessonStatus::Scheduled->value, LessonStatus::Live->value, now(),
                    now(),
                    LessonStatus::Scheduled->value, LessonStatus::Live->value, now(),
                ],
            )
            ->groupBy('student_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($instructorId, collect([$row]))->first();
    }

    /** Bounded recent-lesson rows for the instructor-scoped student detail page — date, subject, status only. */
    public function recentLessonsFor(int $instructorId, int $studentId, int $limit = 10): Collection
    {
        return Lesson::query()
            ->forInstructor($instructorId)
            ->forStudent($studentId)
            ->with(['subject:id,name'])
            ->orderByDesc('starts_at')
            ->limit($limit)
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'starts_at' => $lesson->starts_at,
                'subject' => $lesson->subject?->name ?? 'General',
                'status' => $lesson->status,
            ]);
    }

    /**
     * Hydrates aggregate rows (student_id + counts) with the student's
     * display name/avatar and their learning-plan status with this
     * instructor — two bounded queries regardless of how many rows are
     * being hydrated, never one per row.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, InstructorStudentSummaryData>
     */
    private function hydrate(int $instructorId, Collection $rows): Collection
    {
        $studentIds = $rows->pluck('student_id')->all();

        $students = User::query()
            ->whereIn('id', $studentIds)
            ->with('profile.media')
            ->get(['id', 'first_name', 'last_name', 'name', 'slug'])
            ->keyBy('id');

        $planStatuses = StudentLearningPlan::query()
            ->where('primary_instructor_user_id', $instructorId)
            ->whereIn('student_user_id', $studentIds)
            ->latest('updated_at')
            ->get(['student_user_id', 'status'])
            ->unique('student_user_id')
            ->keyBy('student_user_id');

        return $rows->map(function (object $row) use ($students, $planStatuses): InstructorStudentSummaryData {
            $student = $students->get((int) $row->student_id);

            return new InstructorStudentSummaryData(
                studentId: (int) $row->student_id,
                studentSlug: $student?->slug ?? '',
                name: $student?->name ?? 'Student',
                avatarUrl: $student?->profile?->avatarThumbUrl,
                lessonsCount: (int) $row->lessons_count,
                completedLessons: (int) $row->completed_lessons,
                upcomingLessonsCount: (int) $row->upcoming_lessons_count,
                lastLessonAt: $row->last_lesson_at !== null ? CarbonImmutable::parse($row->last_lesson_at) : null,
                nextLessonAt: $row->next_lesson_at !== null ? CarbonImmutable::parse($row->next_lesson_at) : null,
                learningPlanStatus: $planStatuses->get((int) $row->student_id)?->status,
            );
        })->values();
    }
}
