<?php

declare(strict_types=1);

namespace App\Homework\Services;

use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Enums\AcademicStatus;
use App\Homework\Actions\AssignHomeworkAction;
use App\Homework\Actions\ReviewHomeworkAction;
use App\Homework\Actions\SubmitHomeworkAction;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Enums\HomeworkResourceCollection;
use App\Homework\Enums\HomeworkResourceStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Exceptions\HomeworkException;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkResource;
use App\Models\HomeworkResourceVersion;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Student\LearningPlanProgressService;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class HomeworkService implements HomeworkServiceInterface
{
    public function __construct(
        private readonly HomeworkRepositoryInterface $repository,
        private readonly SubmitHomeworkAction $submitAction,
        private readonly ReviewHomeworkAction $reviewAction,
        private readonly StudentLifecycleService $lifecycle,
        private readonly HomeworkContextValidator $context,
        private readonly AssignHomeworkAction $assignAction,
        private readonly AuditTrailService $audit,
        private readonly LearningPlanProgressService $progress,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function assign(
        User $instructor,
        User $student,
        array $attributes,
        ?string $bookingId = null,
        ?int $learningPlanId = null,
    ): HomeworkAssignment {
        // Explicit actor context. Only instructors
        // assign homework (SRS §11.32); there is no admin assignment
        // path, and Gate::before never reaches direct service calls.
        if (! $instructor->hasRole('instructor')) {
            throw new AuthorizationException('Only instructors can assign homework.');
        }

        // Country-level Homework feature enforcement. Resolved against
        // the instructor's own country, since assigning homework is an
        // instructor action.
        if (! $this->countryFeatures->isEnabled(CountryFeature::Homework, $this->countryResolver->forInstructor($instructor))) {
            throw new HomeworkException('Homework is not currently available for your country.');
        }

        return DB::transaction(function () use ($instructor, $student, $attributes, $bookingId, $learningPlanId): HomeworkAssignment {
            // Fresh row-locked reads inside the transaction: a plan
            // archived or a booking invalidated after UI selection is
            // caught here, not trusted from the client.
            [$booking, $plan] = $this->context->validate($student, $instructor, $bookingId, $learningPlanId);

            $assignment = $this->assignAction->execute([
                ...$attributes,
                'booking_id' => $booking?->id,
                'learning_plan_id' => $plan?->id,
                'teacher_id' => $instructor->id,
                'student_id' => $student->id,
            ]);

            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_assigned',
                'Homework assigned.',
                $assignment,
                $this->contextAuditProperties($booking, $plan) + [
                    'due_at' => $assignment->due_at?->toIso8601String(),
                ],
            );

            $this->progress->recalculate($plan, $instructor);

            return $assignment;
        });
    }

    public function changeContext(HomeworkAssignment $assignment, User $actor, array $changes): HomeworkAssignment
    {
        return DB::transaction(function () use ($assignment, $actor, $changes): HomeworkAssignment {
            /** @var HomeworkAssignment $fresh */
            $fresh = HomeworkAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            if ($actor->id !== $fresh->teacher_id) {
                throw new AuthorizationException('Only the assigning instructor can change homework context.');
            }

            // Merge proposed changes over the FRESH row (never the
            // caller's possibly stale model) so the complete final state
            // is validated — a concurrent edit that already cleared the
            // other link makes clearing this one reject as "neither".
            $finalBookingId = array_key_exists('booking_id', $changes) ? $changes['booking_id'] : $fresh->booking_id;
            $finalPlanId = array_key_exists('learning_plan_id', $changes) ? $changes['learning_plan_id'] : $fresh->learning_plan_id;
            $previousPlanId = $fresh->learning_plan_id;

            [$booking, $plan] = $this->context->validate(
                $fresh->student,
                $actor,
                $finalBookingId,
                $finalPlanId === null ? null : (int) $finalPlanId,
                bookingIsNewLink: $finalBookingId !== $fresh->booking_id,
                planIsNewLink: (string) $finalPlanId !== (string) $fresh->learning_plan_id,
            );

            $fresh->fill([
                'booking_id' => $booking?->id,
                'learning_plan_id' => $plan?->id,
            ])->save();

            $this->audit->logUser(
                $actor,
                'homework',
                'homework_context_updated',
                'Homework context updated.',
                $fresh,
                $this->contextAuditProperties($booking, $plan),
            );

            // Only re-derive progress for plans whose homework set
            // actually changed — an unrelated field edit (or a
            // booking-only relink) must never touch either plan.
            if ((string) $previousPlanId !== (string) $plan?->id) {
                $this->progress->recalculate($plan, $actor);

                if ($previousPlanId !== null) {
                    $this->progress->recalculate(
                        StudentLearningPlan::query()->withTrashed()->find($previousPlanId),
                        $actor,
                    );
                }
            }

            return $fresh->refresh();
        });
    }

    /**
     * Safe context references only — no student details, no homework
     * content (SRS §24.x: audit metadata, never payload).
     *
     * @return array<string, mixed>
     */
    private function contextAuditProperties(?Booking $booking, ?StudentLearningPlan $plan): array
    {
        return [
            'linked_to' => match (true) {
                $booking !== null && $plan !== null => 'lesson_and_plan',
                $booking !== null => 'lesson',
                default => 'plan',
            },
            'booking_reference' => $booking?->reference,
            'learning_plan_id' => $plan?->id,
        ];
    }

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

    public function submit(HomeworkAssignment $assignment, string $submissionText, ?UploadedFile $attachment = null): HomeworkAssignment
    {
        return DB::transaction(function () use ($assignment, $submissionText, $attachment): HomeworkAssignment {
            // A submission is BY DEFINITION the
            // assignment's student acting (HomeworkAssignmentPolicy::submit
            // already restricts the HTTP actor to exactly that student),
            // so the lifecycle guard applies to the assignment's student
            // unconditionally. The instructor review() path below is
            // untouched. Checked inside the transaction so the locked
            // profile read serializes against a concurrent suspension.
            $this->lifecycle->assertEligibleForStudentAction($assignment->student);

            $submitted = $this->submitAction->execute($assignment, $submissionText, $attachment);

            if ($attachment !== null) {
                $this->audit->logUser(
                    $assignment->student,
                    'homework',
                    'homework_resource_added',
                    'Homework submission attachment uploaded.',
                    $submitted,
                    ['collection' => HomeworkResourceCollection::SubmissionAttachment->value],
                );
            }

            return $submitted;
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
        return DB::transaction(function () use ($assignment, $feedback, $grade): HomeworkAssignment {
            $graded = $this->reviewAction->execute($assignment, $feedback, $grade);

            // No actor is threaded through this pre-existing signature —
            // the assigning teacher (the only one who can grade, per
            // HomeworkAssignmentPolicy) is attributed on the plan's
            // updated_by, same as every other homework-triggered
            // recalculation in this service.
            $this->progress->recalculate($graded->learningPlan, $graded->teacher);

            return $graded;
        });
    }

    /**
     * A small, fixed bound — no existing configurable
     * size/count-limit settings pattern exists anywhere in the codebase
     * (Messaging/KYC uploads hardcode their limits in FormRequest/Livewire
     * validation rules too), so this mirrors that precedent rather than
     * introducing a new Settings class for a single field.
     */
    private const int MAX_INSTRUCTOR_RESOURCES = 5;

    public function addResource(User $instructor, HomeworkAssignment $assignment, UploadedFile $file): Media
    {
        if ($instructor->id !== $assignment->teacher_id) {
            throw new AuthorizationException('Only the assigning instructor can add resources to this assignment.');
        }

        return DB::transaction(function () use ($instructor, $assignment, $file): Media {
            /** @var HomeworkAssignment $fresh */
            $fresh = HomeworkAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            $this->assertResourcesMutable($fresh);

            if ($fresh->getMedia(HomeworkResourceCollection::InstructorResources->value)->count() >= self::MAX_INSTRUCTOR_RESOURCES) {
                throw new HomeworkException('This assignment already has the maximum number of resources.');
            }

            $media = $fresh->addMedia($file)->toMediaCollection(HomeworkResourceCollection::InstructorResources->value);

            // Metadata only — never the file content or storage path
            // (requirement #8).
            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_resource_added',
                'Homework resource added.',
                $fresh,
                ['collection' => HomeworkResourceCollection::InstructorResources->value, 'media_id' => $media->id],
            );

            return $media;
        });
    }

    public function removeResource(User $instructor, HomeworkAssignment $assignment, string $mediaId): void
    {
        if ($instructor->id !== $assignment->teacher_id) {
            throw new AuthorizationException('Only the assigning instructor can remove resources from this assignment.');
        }

        DB::transaction(function () use ($instructor, $assignment, $mediaId): void {
            /** @var HomeworkAssignment $fresh */
            $fresh = HomeworkAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            $this->assertResourcesMutable($fresh);

            // Scoped to this assignment's own instructor-resources
            // collection — a foreign or wrong-collection media id can
            // never be found here, let alone deleted.
            $media = $fresh->getMedia(HomeworkResourceCollection::InstructorResources->value)
                ->first(fn (Media $candidate): bool => (string) $candidate->id === $mediaId);

            if ($media === null) {
                throw new HomeworkException('That resource no longer exists on this assignment.');
            }

            $media->delete();

            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_resource_removed',
                'Homework resource removed.',
                $fresh,
                ['collection' => HomeworkResourceCollection::InstructorResources->value, 'media_id' => $mediaId],
            );
        });
    }

    /**
     * Shared instructor-resource mutation guard: finalized (graded)
     * homework and completed/archived learning plans are immutable
     * (requirement #4), mirroring HomeworkContextValidator's own
     * isWritable() check. Historical resources remain visible/downloadable
     * regardless — only new add/remove mutations are blocked.
     */
    private function assertResourcesMutable(HomeworkAssignment $assignment): void
    {
        if ($assignment->status === HomeworkStatus::Graded) {
            throw new HomeworkException('Resources cannot be modified once homework has been graded.');
        }

        $plan = $assignment->learning_plan_id !== null
            ? StudentLearningPlan::query()->withTrashed()->find($assignment->learning_plan_id)
            : null;

        if ($plan !== null && ! $plan->status->isWritable()) {
            throw new HomeworkException('Resources cannot be modified for a completed or archived learning plan.');
        }
    }

    // ── Reusable, versioned resource library ──────────────────────────

    public function createResource(User $instructor, array $attributes): HomeworkResource
    {
        if (! $instructor->hasRole('instructor')) {
            throw new AuthorizationException('Only instructors can create resources.');
        }

        $this->assertActiveAcademicMetadata($attributes);

        return DB::transaction(function () use ($instructor, $attributes): HomeworkResource {
            $resource = HomeworkResource::query()->create([
                'instructor_id' => $instructor->id,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'subject_id' => $attributes['subject_id'] ?? null,
                'academic_level_id' => $attributes['academic_level_id'] ?? null,
                'status' => HomeworkResourceStatus::Active,
            ]);

            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_resource_library_created',
                'Homework resource created.',
                $resource,
                ['title' => $resource->title],
            );

            return $resource;
        });
    }

    public function publishResourceVersion(User $instructor, HomeworkResource $resource, UploadedFile $file): HomeworkResourceVersion
    {
        if ($instructor->id !== $resource->instructor_id) {
            throw new AuthorizationException('Only the resource owner can publish a new version.');
        }

        return DB::transaction(function () use ($instructor, $resource, $file): HomeworkResourceVersion {
            // Locking the parent resource row serializes concurrent
            // publishes for the SAME resource, so the next-version-number
            // read below can never race — the unique DB constraint is a
            // pure backstop, not the primary guard.
            /** @var HomeworkResource $fresh */
            $fresh = HomeworkResource::query()->whereKey($resource->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === HomeworkResourceStatus::Archived) {
                throw new HomeworkException('Archived resources cannot receive new versions.');
            }

            $nextVersion = (int) ($fresh->versions()->max('version_number') ?? 0) + 1;

            $version = HomeworkResourceVersion::query()->create([
                'homework_resource_id' => $fresh->id,
                'version_number' => $nextVersion,
                'created_by' => $instructor->id,
                'published_at' => now(),
            ]);

            // The version row is created first and is immutable from here
            // on — a prior version's media is never touched by this call.
            $version->addMedia($file)->toMediaCollection('file');

            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_resource_version_published',
                'Homework resource version published.',
                $fresh,
                ['version_number' => $nextVersion],
            );

            return $version->refresh();
        });
    }

    public function archiveResource(User $instructor, HomeworkResource $resource): HomeworkResource
    {
        if ($instructor->id !== $resource->instructor_id) {
            throw new AuthorizationException('Only the resource owner can archive it.');
        }

        return DB::transaction(function () use ($instructor, $resource): HomeworkResource {
            /** @var HomeworkResource $fresh */
            $fresh = HomeworkResource::query()->whereKey($resource->getKey())->lockForUpdate()->firstOrFail();
            $fresh->update(['status' => HomeworkResourceStatus::Archived]);

            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_resource_archived',
                'Homework resource archived.',
                $fresh,
                [],
            );

            return $fresh->refresh();
        });
    }

    public function attachResourceVersion(User $instructor, HomeworkAssignment $assignment, HomeworkResourceVersion $version): void
    {
        if ($instructor->id !== $assignment->teacher_id) {
            throw new AuthorizationException('Only the assigning instructor can attach resources to this assignment.');
        }

        // Requirement #7: an instructor may only attach versions from
        // their OWN library — never another instructor's private
        // resource, even onto their own assignment.
        $resource = $version->resource;

        if ($instructor->id !== $resource->instructor_id) {
            throw new AuthorizationException('You can only attach resources from your own library.');
        }

        DB::transaction(function () use ($instructor, $assignment, $version, $resource): void {
            /** @var HomeworkAssignment $fresh */
            $fresh = HomeworkAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            $this->assertResourcesMutable($fresh);

            if ($resource->status === HomeworkResourceStatus::Archived) {
                throw new HomeworkException('Archived resources cannot be newly attached.');
            }

            if ($fresh->resourceVersions()->where('homework_resource_version_id', $version->id)->exists()) {
                throw new HomeworkException('This resource version is already attached to this assignment.');
            }

            $fresh->resourceVersions()->attach($version->id, ['attached_by' => $instructor->id]);

            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_resource_version_attached',
                'Homework resource version attached.',
                $fresh,
                ['homework_resource_version_id' => $version->id],
            );
        });
    }

    public function detachResourceVersion(User $instructor, HomeworkAssignment $assignment, HomeworkResourceVersion $version): void
    {
        if ($instructor->id !== $assignment->teacher_id) {
            throw new AuthorizationException('Only the assigning instructor can detach resources from this assignment.');
        }

        DB::transaction(function () use ($instructor, $assignment, $version): void {
            /** @var HomeworkAssignment $fresh */
            $fresh = HomeworkAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            $this->assertResourcesMutable($fresh);

            $fresh->resourceVersions()->detach($version->id);

            $this->audit->logUser(
                $instructor,
                'homework',
                'homework_resource_version_detached',
                'Homework resource version detached.',
                $fresh,
                ['homework_resource_version_id' => $version->id],
            );
        });
    }

    /** @param array<string, mixed> $attributes */
    private function assertActiveAcademicMetadata(array $attributes): void
    {
        if (($attributes['subject_id'] ?? null) !== null) {
            $subject = Subject::query()->find($attributes['subject_id']);

            if ($subject === null || $subject->status !== AcademicStatus::Active) {
                throw new HomeworkException('The selected subject is not available.');
            }
        }

        if (($attributes['academic_level_id'] ?? null) !== null) {
            $level = AcademicLevel::query()->find($attributes['academic_level_id']);

            if ($level === null || $level->status !== AcademicStatus::Active) {
                throw new HomeworkException('The selected academic level is not available.');
            }
        }
    }
}
