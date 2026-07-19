<?php

declare(strict_types=1);

namespace App\Homework\Contracts;

use App\Homework\Exceptions\HomeworkException;
use App\Homework\Exceptions\InvalidHomeworkContextException;
use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface HomeworkServiceInterface
{
    /**
     * Phase 24J — GAP-021: create a homework assignment linked to a
     * completed lesson (booking) and/or a writable learning plan owned
     * by the student/instructor pair. At least one link is mandatory.
     *
     * @param  array<string, mixed>  $attributes  title/subject/description/due_at
     *
     * @throws AuthorizationException when the actor is not an instructor
     * @throws InvalidHomeworkContextException when the context is missing, foreign, or ineligible
     */
    public function assign(
        User $instructor,
        User $student,
        array $attributes,
        ?string $bookingId = null,
        ?int $learningPlanId = null,
    ): HomeworkAssignment;

    /**
     * Change the lesson/plan links of an existing assignment. The final
     * merged state is revalidated in full; the last remaining link can
     * never be cleared.
     *
     * @param  array<string, mixed>  $changes  any of: booking_id (?string), learning_plan_id (?int)
     *
     * @throws AuthorizationException when the actor is not the assigning instructor
     * @throws InvalidHomeworkContextException
     */
    public function changeContext(HomeworkAssignment $assignment, User $actor, array $changes): HomeworkAssignment;

    public function paginatedForStudent(int $studentId, int $perPage = 15): LengthAwarePaginator;

    public function statsForStudent(int $studentId): object;

    /** @return Collection<int, HomeworkAssignment> */
    public function attentionForStudent(int $studentId, int $limit = 3): Collection;

    /** @throws HomeworkException when already submitted/graded */
    public function submit(HomeworkAssignment $assignment, string $submissionText): HomeworkAssignment;

    /** Submissions awaiting the teacher's review, oldest submitted first. */
    public function paginatedForTeacher(int $teacherId, int $perPage = 20): LengthAwarePaginator;

    /** @return Collection<int, HomeworkAssignment> most recently graded assignments for the teacher. */
    public function recentlyGradedForTeacher(int $teacherId, int $limit = 10): Collection;

    public function pendingReviewCountForTeacher(int $teacherId): int;

    /** @throws HomeworkException when the assignment is not awaiting review */
    public function review(HomeworkAssignment $assignment, string $feedback, ?string $grade = null): HomeworkAssignment;
}
