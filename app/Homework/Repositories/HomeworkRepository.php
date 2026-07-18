<?php

declare(strict_types=1);

namespace App\Homework\Repositories;

use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class HomeworkRepository implements HomeworkRepositoryInterface
{
    public function paginatedForStudent(int $studentId, int $perPage = 15): LengthAwarePaginator
    {
        return HomeworkAssignment::query()
            ->forStudent($studentId)
            ->with(['teacher', 'booking.type'])
            ->orderByRaw("status = 'pending' desc")
            ->orderBy('due_at')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): HomeworkAssignment
    {
        return HomeworkAssignment::findOrFail($id);
    }

    public function statsForStudent(int $studentId): object
    {
        return HomeworkAssignment::query()
            ->forStudent($studentId)
            ->selectRaw(
                'SUM(status = ? AND due_at >= ?) as pending,
                 SUM(status = ? AND due_at < ?) as overdue,
                 SUM(status = ?) as graded',
                [
                    HomeworkStatus::Pending->value, now(),
                    HomeworkStatus::Pending->value, now(),
                    HomeworkStatus::Graded->value,
                ],
            )
            ->first();
    }

    public function attentionForStudent(int $studentId, int $limit = 3): Collection
    {
        return HomeworkAssignment::query()
            ->forStudent($studentId)
            ->where('status', HomeworkStatus::Pending)
            ->with('booking.type')
            ->orderByRaw('due_at < ? desc', [now()])
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
    }

    public function paginatedForTeacher(int $teacherId, int $perPage = 20): LengthAwarePaginator
    {
        return HomeworkAssignment::query()
            ->forTeacher($teacherId)
            ->where('status', HomeworkStatus::Submitted)
            ->with(['student:id,first_name,last_name,name'])
            ->orderBy('submitted_at')
            ->paginate($perPage);
    }

    public function recentlyGradedForTeacher(int $teacherId, int $limit = 10): Collection
    {
        return HomeworkAssignment::query()
            ->forTeacher($teacherId)
            ->where('status', HomeworkStatus::Graded)
            ->with(['student:id,first_name,last_name,name'])
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    public function pendingReviewCountForTeacher(int $teacherId): int
    {
        return HomeworkAssignment::query()
            ->forTeacher($teacherId)
            ->where('status', HomeworkStatus::Submitted)
            ->count();
    }
}
