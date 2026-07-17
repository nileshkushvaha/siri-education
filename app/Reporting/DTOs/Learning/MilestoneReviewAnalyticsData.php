<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Learning;

/**
 * Phase 18F — milestone and progress-review analytics. In this
 * codebase every `learning_plan_milestones` row is already
 * plan-assigned (there is no separate curriculum milestone-definition
 * table), so no definition can be miscounted as an achievement; an
 * achievement is strictly status Completed with its authoritative
 * `completed_at`. Milestone reopening has no domain pathway → no
 * reopened metric exists.
 *
 * A progress review is a `learning_plan_reviews` row; "completed" =
 * `reviewed_at` set (stamped by LearningPlanService::createReview).
 * Reviews carry no due date — "due" is the PLAN status `review_due`
 * (manually set), reported as a current-state plan count. Review
 * overdue is therefore NOT definable and is deliberately absent.
 * Private review narrative fields are never carried here.
 *
 * @param  array<string, int>  $currentMilestonesByStatus  LearningPlanMilestoneStatus::value => count
 */
final readonly class MilestoneReviewAnalyticsData
{
    public function __construct(
        public int $milestonesAchievedInPeriod,
        public array $currentMilestonesByStatus,
        public int $plansWithMilestones,
        public int $activePlansWithoutMilestones,
        public int $reviewsCompletedInPeriod,
        public int $plansCurrentlyReviewDue,
        public int $plansReviewedInPeriod,
    ) {}
}
