<?php

declare(strict_types=1);

namespace App\Homework\Services;

use App\Homework\Actions\AssignHomeworkAction;
use App\Homework\Actions\ReviewHomeworkAction;
use App\Homework\Actions\SubmitHomeworkAction;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    ) {}

    public function assign(
        User $instructor,
        User $student,
        array $attributes,
        ?string $bookingId = null,
        ?int $learningPlanId = null,
    ): HomeworkAssignment {
        // Phase 24J — GAP-021: explicit actor context. Only instructors
        // assign homework (SRS §11.32); there is no admin assignment
        // path, and Gate::before never reaches direct service calls.
        if (! $instructor->hasRole('instructor')) {
            throw new AuthorizationException('Only instructors can assign homework.');
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

    public function submit(HomeworkAssignment $assignment, string $submissionText): HomeworkAssignment
    {
        return DB::transaction(function () use ($assignment, $submissionText): HomeworkAssignment {
            // Phase 24H.2 — GAP-013: a submission is BY DEFINITION the
            // assignment's student acting (HomeworkAssignmentPolicy::submit
            // already restricts the HTTP actor to exactly that student),
            // so the lifecycle guard applies to the assignment's student
            // unconditionally. The instructor review() path below is
            // untouched. Checked inside the transaction so the locked
            // profile read serializes against a concurrent suspension.
            $this->lifecycle->assertEligibleForStudentAction($assignment->student);

            return $this->submitAction->execute($assignment, $submissionText);
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
        return DB::transaction(
            fn (): HomeworkAssignment => $this->reviewAction->execute($assignment, $feedback, $grade),
        );
    }
}
