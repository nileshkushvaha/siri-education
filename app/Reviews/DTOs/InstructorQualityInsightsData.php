<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

/**
 * Everything the instructor-facing "Reviews & Quality" dashboard
 * section needs, other than the paginated recent-reviews list (which
 * stays a separate, independently-paginated call — see
 * `InstructorQualityInsightsServiceInterface::recentReviewsFor()`).
 * `ratingSummary` is the exact InstructorRatingSummaryData DTO, reused
 * unchanged — no value on this DTO is ever recalculated from it.
 */
final readonly class InstructorQualityInsightsData
{
    /**
     * @param  list<DimensionInsightData>  $topDimensions  highest-rated dimensions with enough data to be meaningful — may be empty
     * @param  list<DimensionInsightData>  $improvementAreas  lowest-rated dimensions with enough data to be meaningful — may be empty
     * @param  list<FeedbackTagCountData>  $feedbackTags  configured tags selected on eligible published reviews, most-frequent first
     */
    public function __construct(
        public InstructorRatingSummaryData $ratingSummary,
        public array $topDimensions,
        public array $improvementAreas,
        public array $feedbackTags,
    ) {}
}
