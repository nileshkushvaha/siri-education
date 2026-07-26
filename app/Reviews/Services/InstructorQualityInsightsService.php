<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Models\User;
use App\Reviews\Contracts\InstructorQualityInsightsServiceInterface;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\DTOs\DimensionInsightData;
use App\Reviews\DTOs\FeedbackTagCountData;
use App\Reviews\DTOs\InstructorQualityInsightsData;
use App\Reviews\DTOs\InstructorRatingSummaryData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Composes existing read paths into the instructor-
 * facing "Reviews & Quality" dashboard section — no new aggregate, no
 * new review projection, no recalculated average. `ratingSummary`
 * comes straight from `InstructorRatingAggregateService::summaryFor()`
 * unchanged; recent reviews come straight from
 * `PublicInstructorReviewService::paginatedReviewsFor()` unchanged.
 * The only genuinely new logic here is picking out which dimensions
 * are highest/lowest (with a minimum-sample gate) and counting
 * configured tags — both deterministic, no inference, no AI.
 */
final class InstructorQualityInsightsService implements InstructorQualityInsightsServiceInterface
{
    /**
     * A dimension needs at least this many ratings before it's
     * confident enough to call out as a highlight or improvement
     * area — the same "don't draw a conclusion from one review"
     * principle the admin dashboard thresholds already use.
     */
    private const int MIN_DIMENSION_SAMPLE = 3;

    /** On the platform's default 1–5 scale, "highlight" and "improvement" are the two ends, never the broad middle — deliberately conservative so this never reads as a warning. */
    private const float HIGHLIGHT_THRESHOLD = 4.0;

    private const float IMPROVEMENT_THRESHOLD = 3.0;

    private const int MAX_DIMENSIONS_PER_LIST = 3;

    public function __construct(
        private readonly InstructorRatingAggregateServiceInterface $aggregates,
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly PublicInstructorReviewServiceInterface $publicReviews,
    ) {}

    public function insightsFor(User $instructor): InstructorQualityInsightsData
    {
        $summary = $this->aggregates->summaryFor($instructor->id);

        return new InstructorQualityInsightsData(
            ratingSummary: $summary,
            topDimensions: $this->topDimensions($summary),
            improvementAreas: $this->improvementAreas($summary),
            feedbackTags: $this->feedbackTags($instructor),
        );
    }

    public function recentReviewsFor(User $instructor, int $perPage = 10): LengthAwarePaginator
    {
        return $this->publicReviews->paginatedReviewsFor($instructor, $perPage);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @return list<DimensionInsightData> */
    private function topDimensions(InstructorRatingSummaryData $summary): array
    {
        return $this->eligibleDimensions($summary)
            ->filter(fn (DimensionInsightData $d): bool => $d->average >= self::HIGHLIGHT_THRESHOLD)
            ->sortByDesc(fn (DimensionInsightData $d): float => $d->average)
            ->take(self::MAX_DIMENSIONS_PER_LIST)
            ->values()
            ->all();
    }

    /** @return list<DimensionInsightData> */
    private function improvementAreas(InstructorRatingSummaryData $summary): array
    {
        return $this->eligibleDimensions($summary)
            ->filter(fn (DimensionInsightData $d): bool => $d->average < self::IMPROVEMENT_THRESHOLD)
            ->sortBy(fn (DimensionInsightData $d): float => $d->average)
            ->take(self::MAX_DIMENSIONS_PER_LIST)
            ->values()
            ->all();
    }

    /** @return Collection<int, DimensionInsightData> every dimension with enough data to be meaningful, regardless of value */
    private function eligibleDimensions(InstructorRatingSummaryData $summary): Collection
    {
        $labels = InstructorRatingSummaryData::dimensionLabels();

        return collect($summary->dimensionAverages)
            ->map(function (?float $average, string $dimension) use ($summary, $labels): ?DimensionInsightData {
                $count = $summary->dimensionCounts[$dimension] ?? 0;

                if ($average === null || $count < self::MIN_DIMENSION_SAMPLE) {
                    return null; // never draw a conclusion from too little data
                }

                return new DimensionInsightData(
                    dimension: $dimension,
                    label: $labels[$dimension] ?? $dimension,
                    average: $average,
                    reviewCount: $count,
                );
            })
            ->filter()
            ->values();
    }

    /** @return list<FeedbackTagCountData> */
    private function feedbackTags(User $instructor): array
    {
        return collect($this->reviews->tagCountsForInstructor($instructor->id))
            ->map(fn (array $entry, string $key): FeedbackTagCountData => new FeedbackTagCountData(
                key: $key,
                label: $entry['label'],
                count: $entry['count'],
            ))
            ->sortByDesc(fn (FeedbackTagCountData $t): int => $t->count)
            ->values()
            ->all();
    }
}
