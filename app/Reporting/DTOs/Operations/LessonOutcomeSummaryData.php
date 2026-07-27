<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

/**
 * Lesson-outcome metrics. Date basis: `lessons.starts_at`
 * for `scheduled` (scheduled-activity view); `lessons.outcome_finalized_at`
 * for every finalized-outcome count (business-event view) — never
 * `lessons.status` alone, which precedes finalization. `disputed` is
 * the CURRENT `LessonStatus::Disputed` lifecycle state (a lesson under
 * active dispute right now, regardless of which outcome triggered it —
 * disputes can reopen any no-show/technical-issue decision) and is
 * therefore genuinely distinct from `technicalIssue` (a finalized
 * outcome reason), not a duplicate of it.
 *
 * @param  array<string, int>  $byOutcome  keyed by `LessonOutcome::value`, finalized lessons only
 */
final readonly class LessonOutcomeSummaryData
{
    public function __construct(
        public int $scheduled,
        public int $finalized,
        public array $byOutcome,
        public int $disputed,
        public int $unfinalizedPastDue,
    ) {}
}
