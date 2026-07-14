<?php

declare(strict_types=1);

namespace App\Feedback\Repositories;

use App\Feedback\Contracts\InstructorStudentFeedbackRepositoryInterface;
use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use Illuminate\Support\Collection;

final class InstructorStudentFeedbackRepository implements InstructorStudentFeedbackRepositoryInterface
{
    public function findForLessonAndInstructor(Lesson $lesson, int $instructorId): ?InstructorStudentFeedback
    {
        return InstructorStudentFeedback::query()
            ->where('lesson_id', $lesson->id)
            ->where('instructor_id', $instructorId)
            ->first();
    }

    public function findManyForLessonsAndInstructor(array $lessonIds, int $instructorId): Collection
    {
        return InstructorStudentFeedback::query()
            ->whereIn('lesson_id', $lessonIds)
            ->where('instructor_id', $instructorId)
            ->get()
            ->keyBy('lesson_id');
    }

    public function create(array $attributes): InstructorStudentFeedback
    {
        return InstructorStudentFeedback::query()->create($attributes);
    }
}
