<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Models\User;
use App\Notifications\Reviews\ReviewReportedNotification;
use App\Reviews\Events\ReviewReported;
use App\Services\Notifications\AdminRecipientResolver;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Recipients: administrators holding the same permission the Phase 17O report-queue widget gates on. */
final class SendReviewReportedNotification implements ShouldQueue
{
    private const string PERMISSION = 'ViewReviewReports';

    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly AdminRecipientResolver $recipients,
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(ReviewReported $event): void
    {
        $report = $event->report;

        foreach ($this->recipients->forPermission(self::PERMISSION) as $admin) {
            /** @var User $admin */
            $key = sprintf('review-reported:%s:%d', $report->id, $admin->id);

            $this->idempotency->once($key, ReviewReportedNotification::class, function () use ($admin, $report): void {
                $admin->notify(new ReviewReportedNotification($report));
            });
        }
    }
}
