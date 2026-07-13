<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\User;
use App\Reviews\Exceptions\InvalidReviewTransitionException;
use App\Reviews\Exceptions\ReviewValidationException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Administrator moderation of a submitted review. Never edits the
 * student's rating, text, or tags — status transitions only. Every
 * action is row-locked, permission-checked, and idempotent for a
 * repeated identical decision; a conflicting decision against a
 * since-changed status throws rather than silently applying.
 */
interface ReviewModerationServiceInterface
{
    /**
     * Publishes a public-review candidate, or moves flagged private
     * feedback to Private — the target is always derived from the
     * review's own `review_mode`, never chosen by the caller. Reason
     * is optional for a straightforward approval.
     *
     * @throws AuthorizationException
     * @throws InvalidReviewTransitionException
     */
    public function approve(LessonReview $review, User $admin, ?string $reason = null): LessonReview;

    /**
     * @throws AuthorizationException
     * @throws ReviewValidationException
     * @throws InvalidReviewTransitionException
     */
    public function reject(LessonReview $review, User $admin, string $reason): LessonReview;

    /**
     * @throws AuthorizationException
     * @throws ReviewValidationException
     * @throws InvalidReviewTransitionException
     */
    public function hide(LessonReview $review, User $admin, string $reason): LessonReview;

    /**
     * @throws AuthorizationException
     * @throws ReviewValidationException
     * @throws InvalidReviewTransitionException
     */
    public function restore(LessonReview $review, User $admin, string $reason): LessonReview;

    /**
     * @throws AuthorizationException
     * @throws ReviewValidationException
     * @throws InvalidReviewTransitionException
     */
    public function archive(LessonReview $review, User $admin, string $reason): LessonReview;
}
