<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Learning;

/**
 * Daily period-event trends, bucketed in the reporting
 * timezone. Bucketing is DST-exact as of TZ-5 (see LocalDaySql) — the
 * former fixed-offset limitation is gone. Every map is keyed Y-m-d over the
 * full period with empty days zero-filled; each series is a true event
 * stream on its own authoritative timestamp — current-state totals are
 * never trended. No smoothing, no forecast.
 *
 * @param  array<string, int>  $plansCreated  student_learning_plans.created_at
 * @param  array<string, int>  $plansActivated  started_at
 * @param  array<string, int>  $plansCompleted  completed_at
 * @param  array<string, int>  $homeworkAssigned  homework_assignments.created_at
 * @param  array<string, int>  $homeworkSubmitted  submitted_at
 * @param  array<string, int>  $milestonesAchieved  learning_plan_milestones.completed_at
 * @param  array<string, int>  $reviewsCompleted  learning_plan_reviews.reviewed_at
 */
final readonly class LearningTrendsData
{
    public function __construct(
        public array $plansCreated,
        public array $plansActivated,
        public array $plansCompleted,
        public array $homeworkAssigned,
        public array $homeworkSubmitted,
        public array $milestonesAchieved,
        public array $reviewsCompleted,
    ) {}
}
