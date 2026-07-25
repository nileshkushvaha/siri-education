<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Compliance\Events\SuspiciousActivityFlagRecorded;
use App\Models\User;
use App\Notifications\Compliance\SuspiciousActivityFlagRecordedNotification;
use App\Services\Notifications\AdminRecipientResolver;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Alerts authorized administrators only for warning/critical-severity
 * flags (item 9 of the phase brief) — low/medium flags are visible in
 * the compliance queue but do not page anyone. Recipients are
 * administrators holding the same permission the compliance Filament
 * resource itself gates its list view on, so "who can act on this"
 * and "who gets notified about this" never drift apart.
 */
final class SendSuspiciousActivityFlagRecordedNotification implements ShouldQueue
{
    private const string PERMISSION = 'ViewAny:SuspiciousActivityFlag';

    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly AdminRecipientResolver $recipients,
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(SuspiciousActivityFlagRecorded $event): void
    {
        $flag = $event->flag;

        if (! $flag->severity->isAlertWorthy()) {
            return;
        }

        foreach ($this->recipients->forPermission(self::PERMISSION) as $admin) {
            /** @var User $admin */
            $key = sprintf('suspicious-activity-flag-recorded:%s:%d', $flag->id, $admin->id);

            $this->idempotency->once($key, SuspiciousActivityFlagRecordedNotification::class, function () use ($admin, $flag): void {
                $admin->notify(new SuspiciousActivityFlagRecordedNotification($flag));
            });
        }
    }
}
