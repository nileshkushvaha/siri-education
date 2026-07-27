<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Learning;

use App\Reporting\DTOs\Operations\LabeledCountRow;

/**
 * Learning Plan analytics. A Learning Plan is a
 * `student_learning_plans` row: a living academic contract created from
 * a learning goal, carrying its own lifecycle (`LearningPlanStatus`)
 * and its own authoritative timestamps (`started_at`, `completed_at`,
 * `archived_at`). A recurring booking is NOT a plan; a goal is NOT a
 * plan.
 *
 * Current-state figures (`totalPlans`, `currentByStatus`,
 * `averageActiveProgressPercent`, breakdowns) are as-of-now and never
 * period-scoped; period-event figures each name their own timestamp
 * basis. `averageActiveProgressPercent` is the mean of the
 * domain-maintained `progress_percent` (§6.17-5 — recalculated by
 * LearningPlanProgressCalculator as the average of each applicable
 * evidence domain: milestones, directly-linked homework, plan-linked
 * lessons, and the latest structured academic-review percentage,
 * excluding a domain entirely when the plan has no applicable
 * records in it; forced to 100 on manual plan completion) across
 * currently Active plans, null when no plan is Active. No completion
 * *rate* exists (§7.2 — cohort denominator unproven in V1).
 *
 * @param  array<string, int>  $currentByStatus  LearningPlanStatus::value => count
 * @param  list<LabeledCountRow>  $bySubject  current plans per subject (historical subjects retained)
 * @param  list<LabeledCountRow>  $byEducationLevel
 * @param  list<LabeledCountRow>  $byInstructor  instructor display name; plans without instructor grouped under a placeholder
 */
final readonly class LearningPlanAnalyticsData
{
    public function __construct(
        public int $totalPlans,
        public array $currentByStatus,
        public int $createdInPeriod,
        public int $activatedInPeriod,
        public int $completedInPeriod,
        public int $archivedInPeriod,
        public ?int $averageActiveProgressPercent,
        public int $activePlansPastTargetDate,
        public int $activePlansWithoutInstructor,
        public array $bySubject,
        public array $byEducationLevel,
        public array $byInstructor,
    ) {}
}
