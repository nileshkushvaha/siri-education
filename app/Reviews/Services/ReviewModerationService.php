<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Models\LessonReview;
use App\Models\User;
use App\Reviews\Actions\TransitionReviewStatusAction;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Events\StudentReviewArchived;
use App\Reviews\Events\StudentReviewHidden;
use App\Reviews\Events\StudentReviewPublished;
use App\Reviews\Events\StudentReviewRejected;
use App\Reviews\Events\StudentReviewRestored;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Administrator moderation. Never touches rating/text/tags — status
 * transitions only, always through TransitionReviewStatusAction's
 * state-machine guard. A repeated identical decision (current status
 * already equals the target) is an idempotent no-op — no duplicate
 * audit entry, no duplicate event; a decision that conflicts with a
 * status changed by someone else since the caller last read it throws
 * rather than silently applying.
 */
final class ReviewModerationService implements ReviewModerationServiceInterface
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly TransitionReviewStatusAction $transition,
        private readonly AuditTrailService $audit,
    ) {}

    public function approve(LessonReview $review, User $admin, ?string $reason = null): LessonReview
    {
        $this->authorize($admin, $review, 'moderate');

        $target = $review->review_mode === LessonReviewEligibilityMode::PrivateFeedback
            ? StudentReviewStatus::Private
            : StudentReviewStatus::Published;

        return DB::transaction(function () use ($review, $admin, $reason, $target): LessonReview {
            $review = $this->reviews->lock($review);

            if ($review->status === $target) {
                return $review; // idempotent repeat of the same decision
            }

            $previous = $review->status;
            $review = $this->transition->execute($review, $target, [
                'moderated_at' => now()->toImmutable()->utc(),
                'moderated_by' => $admin->id,
                'moderation_reason' => $this->sanitizeReason($reason) ?? 'approved',
            ]);

            $this->audit($admin, 'review_approved', $review, $previous, $target, $reason);

            if ($target === StudentReviewStatus::Published) {
                StudentReviewPublished::dispatch($review);
            }

            return $review;
        });
    }

    public function reject(LessonReview $review, User $admin, string $reason): LessonReview
    {
        $this->authorize($admin, $review, 'moderate');
        $this->assertReason($reason);

        return DB::transaction(function () use ($review, $admin, $reason): LessonReview {
            $review = $this->reviews->lock($review);

            if ($review->status === StudentReviewStatus::Rejected) {
                return $review;
            }

            $previous = $review->status;
            $review = $this->transition->execute($review, StudentReviewStatus::Rejected, [
                'moderated_at' => now()->toImmutable()->utc(),
                'moderated_by' => $admin->id,
                'moderation_reason' => $this->sanitizeReason($reason),
            ]);

            $this->audit($admin, 'review_rejected', $review, $previous, StudentReviewStatus::Rejected, $reason);
            StudentReviewRejected::dispatch($review, $reason);

            return $review;
        });
    }

    public function hide(LessonReview $review, User $admin, string $reason): LessonReview
    {
        $this->authorize($admin, $review, 'hide');
        $this->assertReason($reason);

        return DB::transaction(function () use ($review, $admin, $reason): LessonReview {
            $review = $this->reviews->lock($review);

            if ($review->status === StudentReviewStatus::Hidden) {
                return $review;
            }

            $previous = $review->status;
            $review = $this->transition->execute($review, StudentReviewStatus::Hidden, [
                'moderated_at' => now()->toImmutable()->utc(),
                'moderated_by' => $admin->id,
                'moderation_reason' => $this->sanitizeReason($reason),
            ]);

            $this->audit($admin, 'review_hidden', $review, $previous, StudentReviewStatus::Hidden, $reason);
            StudentReviewHidden::dispatch($review, $reason);

            return $review;
        });
    }

    public function restore(LessonReview $review, User $admin, string $reason): LessonReview
    {
        $this->authorize($admin, $review, 'moderate');
        $this->assertReason($reason);

        return DB::transaction(function () use ($review, $admin, $reason): LessonReview {
            $review = $this->reviews->lock($review);

            if ($review->status === StudentReviewStatus::Published) {
                return $review;
            }

            $previous = $review->status;
            $review = $this->transition->execute($review, StudentReviewStatus::Published, [
                'moderated_at' => now()->toImmutable()->utc(),
                'moderated_by' => $admin->id,
                'moderation_reason' => $this->sanitizeReason($reason),
            ]);

            $this->audit($admin, 'review_restored', $review, $previous, StudentReviewStatus::Published, $reason);
            StudentReviewRestored::dispatch($review, $reason);

            return $review;
        });
    }

    public function archive(LessonReview $review, User $admin, string $reason): LessonReview
    {
        $this->authorize($admin, $review, 'moderate');
        $this->assertReason($reason);

        return DB::transaction(function () use ($review, $admin, $reason): LessonReview {
            $review = $this->reviews->lock($review);

            if ($review->status === StudentReviewStatus::Archived) {
                return $review;
            }

            $previous = $review->status;
            $review = $this->transition->execute($review, StudentReviewStatus::Archived, [
                'moderated_at' => now()->toImmutable()->utc(),
                'moderated_by' => $admin->id,
                'moderation_reason' => $this->sanitizeReason($reason),
            ]);

            $this->audit($admin, 'review_archived', $review, $previous, StudentReviewStatus::Archived, $reason);
            StudentReviewArchived::dispatch($review, $reason);

            return $review;
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * The self-moderation exclusion is independently enforced here, not
     * only in LessonReviewPolicy — Gate::before grants super_admin a
     * global permission bypass that skips the policy entirely, and
     * Spatie roles are not mutually exclusive, so an account holding
     * both `instructor` and `super_admin` must still never moderate a
     * review about their own teaching.
     *
     * @throws AuthorizationException
     */
    private function authorize(User $admin, LessonReview $review, string $ability): void
    {
        if ($admin->id === $review->instructor_id) {
            throw new AuthorizationException('You may not moderate a review about your own teaching.');
        }

        if (! $admin->can($ability, $review)) {
            throw new AuthorizationException('You may not moderate this review.');
        }
    }

    /** @throws ReviewValidationException */
    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new ReviewValidationException('This moderation decision requires a reason.');
        }
    }

    private function sanitizeReason(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return mb_substr(trim(strip_tags($reason)), 0, 500);
    }

    private function audit(User $admin, string $event, LessonReview $review, StudentReviewStatus $previous, StudentReviewStatus $new, ?string $reason): void
    {
        $this->audit->logUser(
            $admin,
            self::LOG_NAME,
            $event,
            sprintf('Review %s moderated: %s → %s.', $review->id, $previous->value, $new->value),
            $review,
            [
                'lesson_id' => $review->lesson_id,
                'previous_status' => $previous->value,
                'new_status' => $new->value,
                'reason' => $reason,
                'version' => $review->version,
            ],
        );
    }
}
