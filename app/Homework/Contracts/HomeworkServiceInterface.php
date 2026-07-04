<?php

declare(strict_types=1);

namespace App\Homework\Contracts;

use App\Homework\Exceptions\HomeworkException;
use App\Models\HomeworkAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface HomeworkServiceInterface
{
    public function paginatedForStudent(int $studentId, int $perPage = 15): LengthAwarePaginator;

    public function statsForStudent(int $studentId): object;

    /** @throws HomeworkException when already submitted/graded */
    public function submit(HomeworkAssignment $assignment, string $submissionText): HomeworkAssignment;
}
