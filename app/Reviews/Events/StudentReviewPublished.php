<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A public-review candidate became Published (automatically or by
 * admin approval). After-commit only. Listened to by
 * ReconcileRatingContributionOnStudentReviewPublished,
 * DetectLowRatingQualityRiskOnStudentReviewPublished, and
 * SendReviewPublishedNotifications.
 */
final class StudentReviewPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReview $review,
    ) {}
}
