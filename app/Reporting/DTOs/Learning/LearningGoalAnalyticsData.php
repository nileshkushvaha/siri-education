<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Learning;

use App\Reporting\DTOs\Operations\LabeledCountRow;

/**
 * Learning Goal analytics. A learning goal is a
 * `student_learning_goals` row: a student-defined educational
 * objective. Goals legitimately precede plans — `goalsWithoutPlans` is
 * an informational figure, never an error signal. "Completed" and
 * "archived" use the goal's own authoritative timestamps
 * (`completed_at`, `archived_at`), never `updated_at`.
 *
 * @param  array<string, int>  $currentByStatus  LearningGoalStatus::value => count
 * @param  array<string, int>  $byType  LearningGoalType::value => count (current-state)
 * @param  list<LabeledCountRow>  $bySubject
 * @param  list<LabeledCountRow>  $byEducationLevel
 */
final readonly class LearningGoalAnalyticsData
{
    public function __construct(
        public int $totalGoals,
        public array $currentByStatus,
        public int $createdInPeriod,
        public int $completedInPeriod,
        public int $archivedInPeriod,
        public array $byType,
        public int $goalsLinkedToPlans,
        public int $goalsWithoutPlans,
        public int $studentsWithMultipleActiveGoals,
        public array $bySubject,
        public array $byEducationLevel,
    ) {}
}
