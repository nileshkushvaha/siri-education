<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Enums\LearningPlanMilestoneStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\StudentLearningPlan;

/**
 * The single source of a learning plan's progress percentage (SRS
 * §6.17.5). Averages every SRS evidence domain — milestones,
 * directly-linked homework, plan-linked lessons, and the latest
 * structured academic-review assessment — giving each applicable
 * domain equal weight and excluding a domain entirely when the plan
 * has no applicable evidence in it, rather than penalizing the plan
 * with an invented zero.
 *
 * The review domain is deliberately NOT an average of every review: a
 * review has no draft/finalized lifecycle of its own (`reviewed_at` is
 * always set at creation), so a persisted review with both
 * `reviewed_at` and a non-null `progress_percent` is finalized
 * evidence by construction, and only the LATEST such review is read —
 * it is the instructor's current structured assessment, not a
 * historical checkpoint to be averaged with earlier ones. Reviews
 * without a structured percentage (free text only) never contribute.
 *
 * Read-only: never mutates the plan or its relations.
 */
final class LearningPlanProgressCalculator
{
    public function calculate(StudentLearningPlan $plan): int
    {
        $domainPercentages = array_filter(
            [
                $this->milestonesPercentage($plan),
                $this->homeworkPercentage($plan),
                $this->reviewsPercentage($plan),
                $this->lessonsPercentage($plan),
            ],
            static fn (?int $percentage): bool => $percentage !== null,
        );

        if ($domainPercentages === []) {
            return 0;
        }

        $average = (int) round(array_sum($domainPercentages) / count($domainPercentages));

        return max(0, min(100, $average));
    }

    private function milestonesPercentage(StudentLearningPlan $plan): ?int
    {
        $total = $plan->milestones()->count();

        if ($total === 0) {
            return null;
        }

        $completed = $plan->milestones()
            ->where('status', LearningPlanMilestoneStatus::Completed->value)
            ->count();

        return $this->percentage($completed, $total);
    }

    private function homeworkPercentage(StudentLearningPlan $plan): ?int
    {
        $total = $plan->homeworkAssignments()->count();

        if ($total === 0) {
            return null;
        }

        $completed = $plan->homeworkAssignments()
            ->where('status', HomeworkStatus::Graded->value)
            ->count();

        return $this->percentage($completed, $total);
    }

    /**
     * The latest eligible review's structured percentage — never an
     * average across reviews, never inferred from free text, never a
     * count of "reviews completed." Ordered by the authoritative
     * review timestamp, primary key as a deterministic tie-breaker.
     * reorder() clears the reviews() relation's default
     * orderBy('review_number'), which would otherwise take precedence
     * over the ordering this method actually needs.
     */
    private function reviewsPercentage(StudentLearningPlan $plan): ?int
    {
        $review = $plan->reviews()
            ->reorder()
            ->whereNotNull('reviewed_at')
            ->whereNotNull('progress_percent')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first(['id', 'progress_percent']);

        return $review?->progress_percent;
    }

    /**
     * Only finalized outcomes that represent an expected, settled
     * learning activity enter the denominator: Completed plus every
     * finalized no-show variant (the lesson happened, on schedule, but
     * did not produce completed learning). Pending (unfinalized) and
     * TechnicalIssue (parked in Disputed, not yet resolved) are
     * excluded entirely — not "0%", simply not yet evidence. Cancelled
     * lessons are excluded per SRS §6.17.5 cancellation treatment.
     * Soft-deleted lessons are excluded automatically by Lesson's
     * default query scope.
     */
    private function lessonsPercentage(StudentLearningPlan $plan): ?int
    {
        $countedOutcomes = [
            LessonOutcome::Completed->value,
            LessonOutcome::StudentNoShow->value,
            LessonOutcome::InstructorNoShow->value,
            LessonOutcome::BothAbsent->value,
        ];

        $total = $plan->lessons()->whereIn('outcome', $countedOutcomes)->count();

        if ($total === 0) {
            return null;
        }

        $completed = $plan->lessons()
            ->where('outcome', LessonOutcome::Completed->value)
            ->count();

        return $this->percentage($completed, $total);
    }

    private function percentage(int $completed, int $total): int
    {
        $value = (int) round(($completed / $total) * 100);

        return max(0, min(100, $value));
    }
}
