<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Models\Lesson;
use App\Models\LessonReview;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Repair tool, not the primary update path — the event-driven
 * detectors already keep alerts correct under normal operation. Only
 * re-evaluates recent published reviews and finalized lesson outcomes
 * (per spec — cancellations and report signals are not part of this
 * command's scope), cursored so the full table is never loaded into
 * memory, with per-record failure isolation exactly like
 * `ReviewEligibilityService::expireDue()` /
 * `InstructorRatingAggregateService::rebuildAll()`. Disabled-safe:
 * returns immediately, doing nothing, when quality alerts are off.
 */
final class ReconcileInstructorQualityAlertsAction
{
    public function __construct(
        private readonly QualitySignalRepositoryInterface $signals,
        private readonly DetectLowRatingQualityRiskAction $detectLowRating,
        private readonly DetectInstructorNoShowQualityRiskAction $detectNoShow,
        private readonly ReviewSettings $settings,
    ) {}

    /** @return array{reviews_processed: int, lessons_processed: int} */
    public function execute(): array
    {
        if (! $this->settings->quality_alerts_enabled) {
            return ['reviews_processed' => 0, 'lessons_processed' => 0];
        }

        $now = CarbonImmutable::now();

        $reviewsProcessed = $this->reconcileLowRatings($now);
        $lessonsProcessed = $this->reconcileNoShows($now);

        return ['reviews_processed' => $reviewsProcessed, 'lessons_processed' => $lessonsProcessed];
    }

    private function reconcileLowRatings(CarbonImmutable $now): int
    {
        $since = $now->subDays($this->settings->repeated_low_rating_window_days);
        $processed = 0;

        $this->signals
            ->recentLowPublishedReviews($since, $this->settings->low_rating_threshold)
            ->each(function (LessonReview $review) use (&$processed): void {
                try {
                    $this->detectLowRating->execute($review);
                    $processed++;
                } catch (Throwable $e) {
                    Log::warning('reviews:reconcile-quality-alerts — review skipped after failure', [
                        'review_id' => $review->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $processed;
    }

    private function reconcileNoShows(CarbonImmutable $now): int
    {
        $since = $now->subDays($this->settings->repeated_no_show_window_days);
        $processed = 0;

        $this->signals
            ->recentInstructorNoShowLessons($since)
            ->each(function (Lesson $lesson) use (&$processed): void {
                try {
                    $this->detectNoShow->execute($lesson);
                    $processed++;
                } catch (Throwable $e) {
                    Log::warning('reviews:reconcile-quality-alerts — lesson skipped after failure', [
                        'lesson_id' => $lesson->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $processed;
    }
}
