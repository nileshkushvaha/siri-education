<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReviewEligibility;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A review-eligibility window opened (first creation, or restored
 * after an outcome correction back to Completed). After-commit only.
 * Listened to by SendReviewRequestedNotification.
 */
final class LessonReviewEligibilityOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReviewEligibility $eligibility,
    ) {}
}
