<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student submitted a review (public candidate or private feedback).
 * After-commit only. Listened to by ModerateReviewOnStudentReviewSubmitted
 * (automatic moderation) and SendReviewSubmittedNotification.
 */
final class StudentReviewSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReview $review,
    ) {}
}
