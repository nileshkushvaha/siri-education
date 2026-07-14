<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Notifications\Reviews\ReviewPublishedInstructorNotification;
use App\Notifications\Reviews\ReviewPublishedStudentNotification;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Events\StudentReviewPublished;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The student is always notified; the instructor only for a
 * PublicReview (private feedback never notifies the instructor).
 * Review id + version are baked into both idempotency keys, so a
 * legitimate republish after an edit (a new version) creates a new
 * notification while a replayed event for the same version does not.
 */
final class SendReviewPublishedNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(StudentReviewPublished $event): void
    {
        $review = $event->review;

        if ($review->student !== null) {
            $student = $review->student;
            $key = sprintf('review-published:%s:%d:%d', $review->id, $review->version, $student->id);

            $this->idempotency->once($key, ReviewPublishedStudentNotification::class, function () use ($student, $review): void {
                $student->notify(new ReviewPublishedStudentNotification($review));
            });
        }

        if ($review->review_mode !== LessonReviewEligibilityMode::PublicReview) {
            return;
        }

        if ($review->instructor === null) {
            return;
        }

        $instructor = $review->instructor;
        $key = sprintf('review-published:%s:%d:%d', $review->id, $review->version, $instructor->id);

        $this->idempotency->once($key, ReviewPublishedInstructorNotification::class, function () use ($instructor, $review): void {
            $instructor->notify(new ReviewPublishedInstructorNotification($review));
        });
    }
}
