<?php

declare(strict_types=1);

namespace App\Homework\Contracts;

use App\Models\HomeworkAssignment;
use Carbon\CarbonImmutable;
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

    /** Submissions awaiting the teacher's review, oldest submitted first. */
    public function paginatedForTeacher(int $teacherId, int $perPage = 20): LengthAwarePaginator;

    /** @return Collection<int, HomeworkAssignment> most recently graded assignments for the teacher. */
    public function recentlyGradedForTeacher(int $teacherId, int $limit = 10): Collection;

    public function pendingReviewCountForTeacher(int $teacherId): int;

    /**
     * Assigned/submitted/graded counts among homework this teacher
     * created inside [$periodStartUtc, $periodEndUtcExclusive) —
     * null bounds mean "all time" (no date filter). A single
     * aggregate query, never a materialized collection.
     */
    public function statsForTeacher(int $teacherId, ?CarbonImmutable $periodStartUtc, ?CarbonImmutable $periodEndUtcExclusive): object;
}
