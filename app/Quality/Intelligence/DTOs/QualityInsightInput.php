<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\DTOs;

/**
 * EVERYTHING the model is allowed to see about an instructor, and
 * nothing else. This DTO is the privacy boundary made explicit: if a
 * field is not on this object it cannot reach a provider, so reviewing
 * "what do we send to OpenAI?" means reading one class.
 *
 * Structurally absent, by construction rather than by filtering:
 * student names, student ids, emails, phone numbers, the instructor's
 * own name or contact details, booking/lesson/review identifiers,
 * payment, wallet, earning or payout figures, KYC state, and any
 * authentication data.
 *
 * `statistics` are counts and averages already computed by the
 * Reporting and Reviews domains — this feature invents no metric of its
 * own.
 */
final readonly class QualityInsightInput
{
    /**
     * @param  array<string, int|float|string>  $statistics  period-scoped counts/averages, pre-labelled for display
     * @param  array<string, string>  $dimensionRatings  dimension label => "4.6 (12 ratings)"
     * @param  array<string, int>  $tagCounts  configured review-tag label => count
     * @param  list<AnonymizedReviewExcerpt>  $excerpts
     */
    public function __construct(
        public string $periodLabel,
        public array $statistics,
        public array $dimensionRatings,
        public array $tagCounts,
        public array $excerpts,
        /** How many published reviews existed in the period, before excerpt capping — so "3 excerpts" is never read as "3 reviews". */
        public int $reviewsInPeriod,
    ) {}

    /** True when there is too little to say anything useful — the domain refuses rather than paying for a shrug. */
    public function isTooSparse(): bool
    {
        return $this->reviewsInPeriod === 0
            && ($this->statistics['Completed lessons'] ?? 0) === 0;
    }

    /**
     * The provenance record stored on the insight row: counts only,
     * never text. Enough for an admin to answer "what was this based
     * on?" without the insight table becoming a second copy of review
     * content.
     *
     * @return array<string, mixed>
     */
    public function toProvenance(): array
    {
        return [
            'period_label' => $this->periodLabel,
            'statistics' => $this->statistics,
            'dimension_ratings' => $this->dimensionRatings,
            'tag_counts' => $this->tagCounts,
            'reviews_in_period' => $this->reviewsInPeriod,
            'excerpts_sent' => count($this->excerpts),
        ];
    }
}
