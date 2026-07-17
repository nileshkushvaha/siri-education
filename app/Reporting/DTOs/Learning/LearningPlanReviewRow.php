<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Learning;

use Carbon\CarbonImmutable;

/**
 * One row of the Learning Plan review table. `studentLabel` is
 * masked/unmasked server-side per `ReportAccessContext` full-identity
 * permission — never re-decided in the view. `drillDownUrl` is null
 * when the viewer lacks the existing StudentLearningPlan view
 * permission. `progressPercent` is the domain-maintained value (§7.1
 * Outcome A). Attention flags are source-backed conditions, never a
 * score: reviewDue (plan status), targetDatePassed (active plan past
 * `target_completion_date`), missingInstructor (active plan with no
 * `primary_instructor_user_id`). No note, narrative, email, phone or
 * financial field exists on this DTO.
 */
final readonly class LearningPlanReviewRow
{
    public function __construct(
        public int $planId,
        public string $studentLabel,
        public ?string $instructorLabel,
        public ?string $subjectLabel,
        public string $statusLabel,
        public ?CarbonImmutable $startedAtUtc,
        public ?string $targetDate,
        public int $progressPercent,
        public int $milestonesAchieved,
        public int $milestonesTotal,
        public ?CarbonImmutable $lastReviewAtUtc,
        public bool $reviewDue,
        public bool $targetDatePassed,
        public bool $missingInstructor,
        public ?string $drillDownUrl,
    ) {}
}
