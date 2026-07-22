<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanAssessmentType;
use App\Enums\LearningPlanMilestoneStatus;
use App\Enums\LearningPlanStatus;
use App\Models\LearningPlanAssessment;
use App\Models\LearningPlanMilestone;
use App\Models\LearningPlanReview;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LearningPlanService
{
    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly StudentLifecycleService $lifecycle,
        private readonly LearningPlanProgressService $progress,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraftFromGoal(User $actor, StudentLearningGoal $goal, array $data = []): StudentLearningPlan
    {
        if ($goal->user_id !== $actor->id && ! $actor->can('Create:StudentLearningPlan')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $goal, $data): StudentLearningPlan {
            // Phase 24H.2 — GAP-013: only when the actor IS the owning
            // student — an admin creating a plan via
            // Create:StudentLearningPlan is governed by that permission
            // alone. Checked inside the transaction (locked profile
            // read) so it serializes against a concurrent suspension.
            if ($goal->user_id === $actor->id) {
                $this->lifecycle->assertEligibleForStudentAction($actor);
            }

            /** @var StudentLearningGoal $lockedGoal */
            $lockedGoal = StudentLearningGoal::query()
                ->whereKey($goal->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoOpenPlanForGoal($lockedGoal);

            $plan = StudentLearningPlan::create([
                'student_user_id' => $lockedGoal->user_id,
                'learning_goal_id' => $lockedGoal->id,
                'subject_id' => $lockedGoal->subject_id,
                'academic_level_id' => $lockedGoal->academic_level_id,
                'title' => trim((string) ($data['title'] ?? $lockedGoal->title)),
                'summary' => $data['summary'] ?? $lockedGoal->description,
                'target_completion_date' => $data['target_completion_date'] ?? $lockedGoal->target_date,
                'status' => LearningPlanStatus::Draft,
                'progress_percent' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->logUser($actor, 'learning_plans', 'learning_plan_created', 'Learning plan draft created.', $plan, [
                'learning_plan_id' => $plan->id,
                'learning_goal_id' => $lockedGoal->id,
                'subject_id' => $plan->subject_id,
            ]);

            return $plan->load(['learningGoal', 'subject', 'academicLevel']);
        });
    }

    public function assignInstructor(User $actor, StudentLearningPlan $plan, User $instructor): StudentLearningPlan
    {
        $this->assertCanManagePlan($actor, $plan);
        $this->assertWritable($plan);
        $this->assertBookableInstructor($instructor);

        return DB::transaction(function () use ($actor, $plan, $instructor): StudentLearningPlan {
            $plan->forceFill([
                'primary_instructor_user_id' => $instructor->id,
                'status' => LearningPlanStatus::AwaitingAssessment,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->logUser($actor, 'learning_plans', 'learning_plan_instructor_assigned', 'Learning plan instructor assigned.', $plan, [
                'learning_plan_id' => $plan->id,
                'primary_instructor_user_id' => $instructor->id,
            ]);

            return $plan->refresh()->load(['primaryInstructor']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordAssessment(User $actor, StudentLearningPlan $plan, array $data): LearningPlanAssessment
    {
        $this->assertAssignedInstructorOrAdmin($actor, $plan, 'Create:LearningPlanAssessment');
        $this->assertWritable($plan);

        return DB::transaction(function () use ($actor, $plan, $data): LearningPlanAssessment {
            $type = LearningPlanAssessmentType::tryFrom((string) ($data['assessment_type'] ?? LearningPlanAssessmentType::Initial->value))
                ?? LearningPlanAssessmentType::Initial;

            $assessment = $plan->assessments()->create([
                'student_user_id' => $plan->student_user_id,
                'instructor_user_id' => $actor->hasRole('instructor') ? $actor->id : $plan->primary_instructor_user_id,
                'assessment_type' => $type,
                'strengths' => $data['strengths'] ?? null,
                'weaknesses' => $data['weaknesses'] ?? null,
                'current_level' => $data['current_level'] ?? null,
                'learning_pace' => $data['learning_pace'] ?? null,
                'recommended_focus' => $data['recommended_focus'] ?? null,
                'recommended_frequency' => $data['recommended_frequency'] ?? null,
                'notes' => $data['notes'] ?? null,
                'assessed_at' => $data['assessed_at'] ?? now(),
                'created_by' => $actor->id,
            ]);

            $plan->forceFill([
                'initial_assessment' => $type === LearningPlanAssessmentType::Initial ? ($data['notes'] ?? $data['recommended_focus'] ?? $plan->initial_assessment) : $plan->initial_assessment,
                'current_level_note' => $data['current_level'] ?? $plan->current_level_note,
                'current_focus' => $data['recommended_focus'] ?? $plan->current_focus,
                'recommended_frequency' => $data['recommended_frequency'] ?? $plan->recommended_frequency,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->logUser($actor, 'learning_plans', 'learning_plan_assessment_recorded', 'Learning plan assessment recorded.', $plan, [
                'learning_plan_id' => $plan->id,
                'assessment_id' => $assessment->id,
                'assessment_type' => $type->value,
            ]);

            return $assessment->load('learningPlan');
        });
    }

    public function activate(User $actor, StudentLearningPlan $plan): StudentLearningPlan
    {
        $this->assertCanManagePlan($actor, $plan);
        $this->assertWritable($plan);

        $plan->forceFill([
            'status' => LearningPlanStatus::Active,
            'started_at' => $plan->started_at ?? now(),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->logUser($actor, 'learning_plans', 'learning_plan_activated', 'Learning plan activated.', $plan, [
            'learning_plan_id' => $plan->id,
        ]);

        return $plan->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function adjustPlan(User $actor, StudentLearningPlan $plan, array $data, string $reason): StudentLearningPlan
    {
        $this->assertCanManagePlan($actor, $plan);
        $this->assertWritable($plan);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A change reason is required.']);
        }

        $allowed = collect($data)->only([
            'title',
            'summary',
            'recommended_frequency',
            'recommended_lesson_duration_minutes',
            'preferred_schedule_note',
            'current_focus',
            'current_level_note',
            'target_completion_date',
        ])->all();

        return DB::transaction(function () use ($actor, $plan, $allowed, $reason): StudentLearningPlan {
            $previous = collect($allowed)->keys()->mapWithKeys(fn (string $key): array => [$key => $plan->{$key}])->all();

            $plan->forceFill([...$allowed, 'updated_by' => $actor->id])->save();

            $plan->adjustments()->create([
                'changed_by' => $actor->id,
                'change_reason' => $reason,
                'previous_values' => $previous,
                'new_values' => $allowed,
                'created_at' => now(),
            ]);

            $this->audit->logUser($actor, 'learning_plans', 'learning_plan_adjusted', 'Learning plan adjusted.', $plan, [
                'learning_plan_id' => $plan->id,
                'changed_fields' => array_keys($allowed),
            ]);

            return $plan->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMilestone(User $actor, StudentLearningPlan $plan, array $data): LearningPlanMilestone
    {
        $this->assertAssignedInstructorOrAdmin($actor, $plan, 'Create:LearningPlanMilestone');
        $this->assertWritable($plan);
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Milestone title is required.']);
        }

        return DB::transaction(function () use ($actor, $plan, $data, $title): LearningPlanMilestone {
            $milestone = $plan->milestones()->create([
                'title' => $title,
                'description' => $data['description'] ?? null,
                'target_date' => $data['target_date'] ?? null,
                'status' => LearningPlanMilestoneStatus::Pending,
                'sort_order' => (int) ($data['sort_order'] ?? $plan->milestones()->count()),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->logUser($actor, 'learning_plans', 'learning_plan_milestone_created', 'Learning plan milestone created.', $plan, [
                'learning_plan_id' => $plan->id,
                'milestone_id' => $milestone->id,
            ]);

            $this->progress->recalculate($plan, $actor);

            return $milestone;
        });
    }

    public function completeMilestone(User $actor, LearningPlanMilestone $milestone): LearningPlanMilestone
    {
        $plan = $milestone->learningPlan;
        $this->assertAssignedInstructorOrAdmin($actor, $plan, 'Update:LearningPlanMilestone');
        $this->assertWritable($plan);

        return DB::transaction(function () use ($actor, $milestone, $plan): LearningPlanMilestone {
            $milestone->forceFill([
                'status' => LearningPlanMilestoneStatus::Completed,
                'completed_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->progress->recalculate($plan, $actor);

            $this->audit->logUser($actor, 'learning_plans', 'learning_plan_milestone_completed', 'Learning plan milestone completed.', $plan, [
                'learning_plan_id' => $plan->id,
                'milestone_id' => $milestone->id,
            ]);

            return $milestone->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createReview(User $actor, StudentLearningPlan $plan, array $data): LearningPlanReview
    {
        $this->assertAssignedInstructorOrAdmin($actor, $plan, 'Create:LearningPlanReview');
        $this->assertWritable($plan);

        return DB::transaction(function () use ($actor, $plan, $data): LearningPlanReview {
            $review = $plan->reviews()->create([
                'student_user_id' => $plan->student_user_id,
                'instructor_user_id' => $actor->hasRole('instructor') ? $actor->id : $plan->primary_instructor_user_id,
                'review_number' => (int) ($data['review_number'] ?? ($plan->reviews()->count() + 1)),
                'summary' => $data['summary'] ?? null,
                'progress_notes' => $data['progress_notes'] ?? null,
                'challenges' => $data['challenges'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'homework_quality_note' => $data['homework_quality_note'] ?? null,
                'attendance_note' => $data['attendance_note'] ?? null,
                'next_focus' => $data['next_focus'] ?? null,
                'reviewed_at' => $data['reviewed_at'] ?? now(),
                'created_by' => $actor->id,
            ]);

            if ($plan->status === LearningPlanStatus::ReviewDue) {
                $plan->forceFill(['status' => LearningPlanStatus::Active, 'updated_by' => $actor->id])->save();
            }

            $this->audit->logUser($actor, 'learning_plans', 'learning_plan_progress_review_created', 'Learning plan progress review created.', $plan, [
                'learning_plan_id' => $plan->id,
                'review_id' => $review->id,
                'review_number' => $review->review_number,
            ]);

            return $review;
        });
    }

    public function markReviewDue(User $actor, StudentLearningPlan $plan): StudentLearningPlan
    {
        $this->assertCanManagePlan($actor, $plan);
        $this->assertWritable($plan);

        $plan->forceFill(['status' => LearningPlanStatus::ReviewDue, 'updated_by' => $actor->id])->save();

        $this->audit->logUser($actor, 'learning_plans', 'learning_plan_review_due', 'Learning plan marked review due.', $plan, [
            'learning_plan_id' => $plan->id,
        ]);

        return $plan->refresh();
    }

    public function completePlan(User $actor, StudentLearningPlan $plan): StudentLearningPlan
    {
        $this->assertCanManagePlan($actor, $plan);
        $this->assertWritable($plan);

        $plan->forceFill([
            'status' => LearningPlanStatus::Completed,
            'progress_percent' => 100,
            'completed_at' => now(),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->logUser($actor, 'learning_plans', 'learning_plan_completed', 'Learning plan completed.', $plan, [
            'learning_plan_id' => $plan->id,
        ]);

        return $plan->refresh();
    }

    public function archivePlan(User $actor, StudentLearningPlan $plan): StudentLearningPlan
    {
        $this->assertCanManagePlan($actor, $plan);

        $plan->forceFill([
            'status' => LearningPlanStatus::Archived,
            'archived_at' => now(),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->logUser($actor, 'learning_plans', 'learning_plan_archived', 'Learning plan archived.', $plan, [
            'learning_plan_id' => $plan->id,
        ]);

        return $plan->refresh();
    }

    private function assertNoOpenPlanForGoal(StudentLearningGoal $goal): void
    {
        if (! in_array($goal->status, [LearningGoalStatus::Draft, LearningGoalStatus::Active, LearningGoalStatus::Paused], true)) {
            throw ValidationException::withMessages(['learning_goal_id' => 'Only open learning goals can start a learning plan.']);
        }

        $exists = $goal->learningPlans()
            ->whereNotIn('status', [LearningPlanStatus::Completed->value, LearningPlanStatus::Archived->value])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['learning_goal_id' => 'This learning goal already has an open learning plan.']);
        }
    }

    private function assertBookableInstructor(User $instructor): void
    {
        $isBookable = $instructor->isActive()
            && $instructor->hasRole('instructor')
            && in_array($instructor->profile?->instructor_status?->value, InstructorStatus::bookableValues(), true);

        if (! $isBookable) {
            throw ValidationException::withMessages(['primary_instructor_user_id' => 'Choose an approved or active instructor.']);
        }
    }

    private function assertWritable(StudentLearningPlan $plan): void
    {
        if (! $plan->status->isWritable()) {
            throw ValidationException::withMessages(['learning_plan' => 'Completed or archived learning plans cannot be changed.']);
        }
    }

    private function assertCanManagePlan(User $actor, StudentLearningPlan $plan): void
    {
        if (! $actor->can('update', $plan)) {
            throw new AuthorizationException;
        }
    }

    private function assertAssignedInstructorOrAdmin(User $actor, StudentLearningPlan $plan, string $permission): void
    {
        if ($plan->primary_instructor_user_id !== $actor->id && ! $actor->can($permission)) {
            throw new AuthorizationException;
        }
    }
}
