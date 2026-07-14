<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Notifications\Reviews\ReviewSubmittedNotification;
use App\Reviews\Events\StudentReviewSubmitted;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendReviewSubmittedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(StudentReviewSubmitted $event): void
    {
        $review = $event->review;
        $student = $review->student;

        if ($student === null) {
            return;
        }

        $key = sprintf('review-submitted:%s:%d:%d', $review->id, $review->version, $student->id);

        $this->idempotency->once($key, ReviewSubmittedNotification::class, function () use ($student, $review): void {
            $student->notify(new ReviewSubmittedNotification($review));
        });
    }
}
