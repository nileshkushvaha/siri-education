<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Models\User;
use App\Notifications\Reviews\ReviewModerationRequiredNotification;
use App\Reviews\Events\StudentReviewModerationRequired;
use App\Services\Notifications\AdminRecipientResolver;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Recipients: administrators holding the same permission the Phase 17O moderation-queue widget gates on. */
final class SendReviewModerationRequiredNotification implements ShouldQueue
{
    private const string PERMISSION = 'ViewReviewModerationQueue';

    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly AdminRecipientResolver $recipients,
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(StudentReviewModerationRequired $event): void
    {
        $review = $event->review;

        foreach ($this->recipients->forPermission(self::PERMISSION) as $admin) {
            /** @var User $admin */
            $key = sprintf('review-moderation:%s:%d:%d', $review->id, $review->version, $admin->id);

            $this->idempotency->once($key, ReviewModerationRequiredNotification::class, function () use ($admin, $review): void {
                $admin->notify(new ReviewModerationRequiredNotification($review));
            });
        }
    }
}
