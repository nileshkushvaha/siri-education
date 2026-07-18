<?php

declare(strict_types=1);

namespace App\Homework\Contracts;

use App\Models\HomeworkAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface HomeworkRepositoryInterface
{
    public function paginatedForStudent(int $studentId, int $perPage = 15): LengthAwarePaginator;

    public function findOrFail(string $id): HomeworkAssignment;

    /** Counts of pending (not overdue) / overdue / graded for a student. */
    public function statsForStudent(int $studentId): object;

    /** @return Collection<int, HomeworkAssignment> */
    public function attentionForStudent(int $studentId, int $limit = 3): Collection;
}
