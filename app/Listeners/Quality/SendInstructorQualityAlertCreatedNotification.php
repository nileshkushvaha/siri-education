<?php

declare(strict_types=1);

namespace App\Listeners\Quality;

use App\Models\User;
use App\Notifications\Quality\InstructorQualityAlertCreatedNotification;
use App\Quality\Events\InstructorQualityAlertCreated;
use App\Services\Notifications\AdminRecipientResolver;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Recipients: administrators holding the same permission the Phase
 * 17O quality-alert-queue widget gates on. The instructor the alert is
 * about is never a recipient here.
 */
final class SendInstructorQualityAlertCreatedNotification implements ShouldQueue
{
    private const string PERMISSION = 'ViewInstructorQualityAlerts';

    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly AdminRecipientResolver $recipients,
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(InstructorQualityAlertCreated $event): void
    {
        $alert = $event->alert;

        foreach ($this->recipients->forPermission(self::PERMISSION) as $admin) {
            /** @var User $admin */
            $key = sprintf('quality-alert-created:%s:%d', $alert->id, $admin->id);

            $this->idempotency->once($key, InstructorQualityAlertCreatedNotification::class, function () use ($admin, $alert): void {
                $admin->notify(new InstructorQualityAlertCreatedNotification($alert));
            });
        }
    }
}
