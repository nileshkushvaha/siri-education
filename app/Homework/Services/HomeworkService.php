<?php

declare(strict_types=1);

namespace App\Homework\Services;

use App\Homework\Actions\SubmitHomeworkAction;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\HomeworkAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class HomeworkService implements HomeworkServiceInterface
{
    public function __construct(
        private readonly HomeworkRepositoryInterface $repository,
        private readonly SubmitHomeworkAction $submitAction,
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
        return DB::transaction(
            fn (): HomeworkAssignment => $this->submitAction->execute($assignment, $submissionText),
        );
    }
}
