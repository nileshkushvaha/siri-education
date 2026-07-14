<?php

declare(strict_types=1);

namespace App\Feedback\Contracts;

use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use Illuminate\Support\Collection;

interface InstructorStudentFeedbackRepositoryInterface
{
    public function findForLessonAndInstructor(Lesson $lesson, int $instructorId): ?InstructorStudentFeedback;

    /**
     * @param  list<string>  $lessonIds
     * @return Collection<string, InstructorStudentFeedback> keyed by lesson_id
     */
    public function findManyForLessonsAndInstructor(array $lessonIds, int $instructorId): Collection;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): InstructorStudentFeedback;
}
