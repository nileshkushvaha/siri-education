<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\LessonReview;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Exceptions\InvalidReviewTransitionException;

/**
 * The one place a review's status is written. Guards every change
 * through StudentReviewStatus::canTransitionTo() — mirrors the
 * booking/lesson/earning domains' action-guarded transitions. Callers
 * are responsible for locking the row and committing the surrounding
 * transaction; this only validates and fills.
 *
 * @throws InvalidReviewTransitionException
 */
final class TransitionReviewStatusAction
{
    /** @param array<string, mixed> $extra */
    public function execute(LessonReview $review, StudentReviewStatus $next, array $extra = []): LessonReview
    {
        if (! $review->status->canTransitionTo($next)) {
            throw InvalidReviewTransitionException::between($review->status, $next);
        }

        $review->fill([...$extra, 'status' => $next, 'version' => $review->version + 1])->save();

        return $review;
    }
}
