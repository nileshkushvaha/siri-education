<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Notifications\Reviews\ReviewRequestedNotification;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Events\LessonReviewEligibilityOpened;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Settings\ReviewSettings;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notifies the eligible student that a completed lesson is ready for
 * review. One notification per eligibility-opening version — a
 * restore-after-correction bumps the eligibility's own version, which
 * is baked into the idempotency key, so a legitimate reopening still
 * notifies while a replayed event for the same version does not.
 */
final class SendReviewRequestedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly ReviewSettings $settings,
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(LessonReviewEligibilityOpened $event): void
    {
        $eligibility = $event->eligibility;

        if (! $this->settings->reviews_enabled) {
            return;
        }

        if ($eligibility->status !== LessonReviewEligibilityStatus::Open) {
            return;
        }

        if ($eligibility->eligibility_mode === LessonReviewEligibilityMode::Disabled) {
            return;
        }

        if (now()->isAfter($eligibility->expires_at)) {
            return;
        }

        if ($this->reviews->findForEligibility($eligibility) !== null) {
            return;
        }

        $student = $eligibility->student;

        if ($student === null) {
            return;
        }

        $key = sprintf('review-request:%s:%d:%d', $eligibility->id, $eligibility->version, $student->id);

        $this->idempotency->once($key, ReviewRequestedNotification::class, function () use ($student, $eligibility): void {
            $student->notify(new ReviewRequestedNotification($eligibility));
        });
    }
}
