<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

/**
 * One dimension's standing in an instructor's highlight or
 * improvement-area list — the same average/count already on
 * `InstructorRatingSummaryData`, just picked out and labeled for
 * display. Never a numeric "score" beyond the rating scale itself,
 * and never worded as a problem/warning — that's the consuming
 * view's job to phrase neutrally.
 */
final readonly class DimensionInsightData
{
    public function __construct(
        public string $dimension,
        public string $label,
        public float $average,
        public int $reviewCount,
    ) {}
}
