<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Models\LessonReview;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Quality\DTOs\QualitySignalData;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;
use App\Quality\Support\QualityAlertFingerprint;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;

/**
 * Consumes a just-published public review. Never calculates from the
 * rounded rating aggregate — always recounts actual verified
 * contributing reviews (`QualitySignalRepositoryInterface`) fresh,
 * the same "sums/counts, never a derived value" discipline the Phase
 * 17K aggregate itself follows.
 */
final class DetectLowRatingQualityRiskAction
{
    public function __construct(
        private readonly QualitySignalRepositoryInterface $signals,
        private readonly InstructorQualityAlertRepositoryInterface $alerts,
        private readonly RecordQualityAlertSignalAction $record,
        private readonly ReviewSettings $settings,
    ) {}

    public function execute(LessonReview $review): void
    {
        if (! $this->settings->quality_alerts_enabled) {
            return;
        }

        if ($review->overall_rating > $this->settings->low_rating_threshold) {
            return;
        }

        $now = CarbonImmutable::now();

        if ($this->settings->single_low_rating_alert_enabled) {
            $this->record->execute(new QualitySignalData(
                instructorId: $review->instructor_id,
                type: InstructorQualityAlertType::SingleLowRating,
                sourceType: QualityAlertSourceType::LessonReview,
                sourceId: $review->id,
                fingerprint: QualityAlertFingerprint::forSource(InstructorQualityAlertType::SingleLowRating, $review->instructor_id, $review->id),
                triggeredAt: $now,
                windowStart: null,
                windowEnd: null,
                signalCount: 1,
                summaryMetadata: ['overall_rating' => $review->overall_rating],
            ));
        }

        $windowStart = $now->subDays($this->settings->repeated_low_rating_window_days);
        $count = $this->signals->countLowPublishedReviews($review->instructor_id, $this->settings->low_rating_threshold, $windowStart);

        if ($count < $this->settings->repeated_low_rating_count) {
            return;
        }

        // The episode number is the dedup key: while an episode's alert
        // stays Open/UnderReview, every further low review recomputes
        // the *same* episode number and therefore the *same*
        // fingerprint — RecordQualityAlertSignalAction's unique-index
        // catch makes that a no-op. Only once the episode's alert
        // reaches a terminal status does the count (and therefore the
        // fingerprint) advance, allowing a genuinely new alert.
        $episode = 1 + $this->alerts->countTerminalForInstructorAndType($review->instructor_id, InstructorQualityAlertType::RepeatedLowRatings);

        $this->record->execute(new QualitySignalData(
            instructorId: $review->instructor_id,
            type: InstructorQualityAlertType::RepeatedLowRatings,
            sourceType: QualityAlertSourceType::LessonReview,
            sourceId: $review->id,
            fingerprint: QualityAlertFingerprint::forEpisode(InstructorQualityAlertType::RepeatedLowRatings, $review->instructor_id, $episode),
            triggeredAt: $now,
            windowStart: $windowStart,
            windowEnd: $now,
            signalCount: $count,
            summaryMetadata: ['triggering_review_id' => $review->id],
            episodeNumber: $episode,
        ));
    }
}
