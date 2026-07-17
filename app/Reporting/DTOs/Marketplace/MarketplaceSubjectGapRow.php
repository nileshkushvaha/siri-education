<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Marketplace;

/**
 * One subject-dimension gap: period booking demand versus current
 * active-instructor assignment for the same subject. Rows exist only
 * where one side is zero — a factual mismatch listing, never a score.
 */
final readonly class MarketplaceSubjectGapRow
{
    public function __construct(
        public string $subjectLabel,
        public int $bookingsInPeriod,
        public int $activeInstructors,
    ) {}
}
