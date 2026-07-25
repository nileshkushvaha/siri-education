<?php

declare(strict_types=1);

namespace App\Homework\Contracts;

use App\Homework\Exceptions\HomeworkException;
use App\Homework\Exceptions\InvalidHomeworkContextException;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkResource;
use App\Models\HomeworkResourceVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

    /**
     * GAP-022: $attachment is the student's own optional submission file,
     * attached atomically with the text submission — there is no separate
     * "add submission attachment later" path since resubmission is not
     * implemented.
     *
     * @throws HomeworkException when already submitted/graded, or the file fails validation
     */
    public function submit(HomeworkAssignment $assignment, string $submissionText, ?UploadedFile $attachment = null): HomeworkAssignment;

    /** Submissions awaiting the teacher's review, oldest submitted first. */
    public function paginatedForTeacher(int $teacherId, int $perPage = 20): LengthAwarePaginator;

    /** @return Collection<int, HomeworkAssignment> most recently graded assignments for the teacher. */
    public function recentlyGradedForTeacher(int $teacherId, int $limit = 10): Collection;

    public function pendingReviewCountForTeacher(int $teacherId): int;

    /** @throws HomeworkException when the assignment is not awaiting review */
    public function review(HomeworkAssignment $assignment, string $feedback, ?string $grade = null): HomeworkAssignment;

    /**
     * GAP-022: instructor-provided resource upload. Only the assigning
     * instructor may add resources, and only while the assignment is not
     * yet graded and its linked learning plan (if any) is still writable.
     *
     * @throws AuthorizationException when the actor is not the assigning instructor
     * @throws HomeworkException when the assignment is graded, the plan is not writable, or a limit is exceeded
     */
    public function addResource(User $instructor, HomeworkAssignment $assignment, UploadedFile $file): Media;

    /**
     * @throws AuthorizationException when the actor is not the assigning instructor
     * @throws HomeworkException when the assignment is graded, the plan is not writable, or the media does not belong to this assignment's instructor-resources collection
     */
    public function removeResource(User $instructor, HomeworkAssignment $assignment, string $mediaId): void;

    // ── GAP-022 (37A): reusable, versioned resource library ───────────

    /**
     * @param  array<string, mixed>  $attributes  title/description/subject_id/academic_level_id
     *
     * @throws AuthorizationException when the actor is not an instructor
     * @throws HomeworkException when subject_id/academic_level_id is provided but not active
     */
    public function createResource(User $instructor, array $attributes): HomeworkResource;

    /**
     * Publishes a new immutable version — never mutates an existing one.
     *
     * @throws AuthorizationException when the actor does not own the resource
     * @throws HomeworkException when the resource is archived
     */
    public function publishResourceVersion(User $instructor, HomeworkResource $resource, UploadedFile $file): HomeworkResourceVersion;

    /** @throws AuthorizationException when the actor does not own the resource */
    public function archiveResource(User $instructor, HomeworkResource $resource): HomeworkResource;

    /**
     * @throws AuthorizationException when the actor does not manage the assignment or own the resource
     * @throws HomeworkException when the resource is archived, the assignment/plan is not mutable, or already attached
     */
    public function attachResourceVersion(User $instructor, HomeworkAssignment $assignment, HomeworkResourceVersion $version): void;

    /**
     * @throws AuthorizationException when the actor does not manage the assignment
     * @throws HomeworkException when the assignment/plan is not mutable
     */
    public function detachResourceVersion(User $instructor, HomeworkAssignment $assignment, HomeworkResourceVersion $version): void;
}
