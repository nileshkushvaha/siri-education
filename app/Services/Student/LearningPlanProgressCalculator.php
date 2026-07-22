<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Enums\LearningPlanMilestoneStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\StudentLearningPlan;

/**
 * The single source of a learning plan's progress percentage (SRS
 * §6.17 item 5 / GAP-023). Averages every SRS evidence domain the
 * current data model can reliably attribute to exactly one plan —
 * milestones and directly-linked homework today — giving each
 * applicable domain equal weight and excluding a domain from the
 * average entirely when the plan has no applicable records in it,
 * rather than penalizing the plan with an invented zero.
 *
 * Lessons and periodic reviews are deliberately NOT included yet:
 *
 * - No relationship exists anywhere in the schema between
 *   Lesson/Booking and StudentLearningPlan, so a lesson cannot be
 *   attributed to exactly one plan without inventing a new
 *   relationship — a schema/workflow change, not a calculation fix.
 *   lessonsPercentage() is the preserved boundary for when a real
 *   link exists.
 * - LearningPlanReview carries no structured completion/progress
 *   field (every column is free text) and `reviewed_at` is always
 *   set at creation time — there is no draft state, so every review
 *   row is trivially "done" the instant it exists. Reading a
 *   completion signal from it would require inventing a review
 *   schedule/quota the SRS does not define. reviewsPercentage() is
 *   the preserved boundary for when a structured field is added.
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

    /** Preserved boundary — see class docblock. Not yet computable. */
    private function reviewsPercentage(StudentLearningPlan $plan): ?int
    {
        return null;
    }

    /** Preserved boundary — see class docblock. Not yet computable. */
    private function lessonsPercentage(StudentLearningPlan $plan): ?int
    {
        return null;
    }

    private function percentage(int $completed, int $total): int
    {
        $value = (int) round(($completed / $total) * 100);

        return max(0, min(100, $value));
    }
}
