<?php

declare(strict_types=1);

namespace App\DTOs\InstructorDashboard;

/** One currency's earning totals for the instructor Earnings page — integer minor units only. */
final readonly class InstructorFinanceSummaryData
{
    public function __construct(
        public string $currencyCode,
        public int $pendingHoldMinor,
        public int $releasableMinor,
        public int $settledMinor,
        public int $totalEarnedMinor,
    ) {}
}
