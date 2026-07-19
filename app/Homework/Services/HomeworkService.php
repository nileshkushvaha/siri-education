<?php

declare(strict_types=1);

namespace App\Homework\Services;

use App\Homework\Actions\ReviewHomeworkAction;
use App\Homework\Actions\SubmitHomeworkAction;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\HomeworkAssignment;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class HomeworkService implements HomeworkServiceInterface
{
    public function __construct(
        private readonly HomeworkRepositoryInterface $repository,
        private readonly SubmitHomeworkAction $submitAction,
        private readonly ReviewHomeworkAction $reviewAction,
        private readonly StudentLifecycleService $lifecycle,
    ) {}

    public function paginatedForStudent(int $studentId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginatedForStudent($studentId, $perPage);
    }

    public function statsForStudent(int $studentId): object
    {
        return $this->repository->statsForStudent($studentId);
    }

    public function attentionForStudent(int $studentId, int $limit = 3): Collection
    {
        return $this->repository->attentionForStudent($studentId, $limit);
    }

    public function submit(HomeworkAssignment $assignment, string $submissionText): HomeworkAssignment
    {
        return DB::transaction(function () use ($assignment, $submissionText): HomeworkAssignment {
            // Phase 24H.2 — GAP-013: a submission is BY DEFINITION the
            // assignment's student acting (HomeworkAssignmentPolicy::submit
            // already restricts the HTTP actor to exactly that student),
            // so the lifecycle guard applies to the assignment's student
            // unconditionally. The instructor review() path below is
            // untouched. Checked inside the transaction so the locked
            // profile read serializes against a concurrent suspension.
            $this->lifecycle->assertEligibleForStudentAction($assignment->student);

            return $this->submitAction->execute($assignment, $submissionText);
        });
    }

    public function paginatedForTeacher(int $teacherId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginatedForTeacher($teacherId, $perPage);
    }

    public function recentlyGradedForTeacher(int $teacherId, int $limit = 10): Collection
    {
        return $this->repository->recentlyGradedForTeacher($teacherId, $limit);
    }

    public function pendingReviewCountForTeacher(int $teacherId): int
    {
        return $this->repository->pendingReviewCountForTeacher($teacherId);
    }

    public function review(HomeworkAssignment $assignment, string $feedback, ?string $grade = null): HomeworkAssignment
    {
        return DB::transaction(
            fn (): HomeworkAssignment => $this->reviewAction->execute($assignment, $feedback, $grade),
        );
    }
}
