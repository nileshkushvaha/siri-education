<?php

declare(strict_types=1);

namespace App\DTOs\Instructor;

/**
 * Current-vs-previous-period rating snapshot (Phase 23P). Never a
 * recalculation of the all-time InstructorRatingAggregate — this is a
 * period slice computed with the exact same eligibility predicate
 * (ReviewContributionEligibility::qualifies()) the aggregate itself
 * uses, since the aggregate has no period dimension to slice.
 */
final readonly class QualityTrendData
{
    public function __construct(
        public ?float $averageRatingCurrent,
        public ?float $averageRatingPrevious,
        public int $reviewCountCurrent,
        public int $reviewCountPrevious,
        public bool $hasComparison,
    ) {}
}
