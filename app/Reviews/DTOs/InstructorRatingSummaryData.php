<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

/**
 * Read-only public rating summary for one instructor — deliberately
 * excludes anything private: no student identity, no review text, no
 * internal moderation reasons, no internal quality score. Safe to
 * expose to a future public profile / instructor dashboard as-is.
 */
final readonly class InstructorRatingSummaryData
{
    /**
     * @param  array<string, int>  $ratingDistribution  e.g. ['5' => 12, '4' => 3]
     * @param  array<string, ?float>  $dimensionAverages  keyed by dimension name
     */
    public function __construct(
        public int $instructorId,
        public int $reviewCount,
        public ?float $averageRating,
        public array $ratingDistribution,
        public array $dimensionAverages,
        public int $paidReviewCount,
        public int $demoReviewCount,
    ) {}

    /** Display labels for the `dimensionAverages` keys — the single source of truth for any consumer rendering this DTO. */
    public static function dimensionLabels(): array
    {
        return [
            'teaching_quality' => 'Teaching Quality',
            'communication' => 'Communication',
            'punctuality' => 'Punctuality',
            'preparedness' => 'Preparedness',
            'learning_value' => 'Learning Value',
        ];
    }
}
