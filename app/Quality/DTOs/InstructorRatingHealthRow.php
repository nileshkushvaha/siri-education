<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

/**
 * One row in the "low-rated" / "highly-rated" instructor dashboard
 * lists. Deliberately has no numeric "quality score" field — only the
 * same average/distribution/counts the public rating aggregate (Phase
 * 17K) and quality-alert domain (Phase 17N) already expose.
 */
final readonly class InstructorRatingHealthRow
{
    /** @param array<string, int> $ratingDistribution */
    public function __construct(
        public int $instructorId,
        public string $instructorName,
        public float $averageRating,
        public int $eligibleReviewCount,
        public array $ratingDistribution,
        public ?float $recentRatingTrend,
        public int $openQualityAlertCount,
        public int $instructorNoShowCount,
        public int $instructorAttributedCancellationCount,
    ) {}
}
