<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Events\OperationalAlertRecorded;
use App\Models\User;
use App\Notifications\Alerts\OperationalAlertRecordedNotification;
use App\Services\Notifications\AdminRecipientResolver;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Alerts authorized administrators only for High/Critical severity
 * (requirement #6) — Info/Warning stay visible in the alert queue but
 * page no one. Recipients are resolved by the alert's own category
 * (SRS §26.29 "Alert Routing") via the existing
 * `AdminRecipientResolver::forPermission()` mechanism — reusing the
 * exact same domain-view permission a relevant manager already holds
 * ({@see OperationalAlertCategory::notificationPermission()}),
 * never a new team/role system. `super_admin`s are always included by
 * that resolver, so `CrossDomainCritical` still reaches them.
 */
final class SendOperationalAlertRecordedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly AdminRecipientResolver $recipients,
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handle(OperationalAlertRecorded $event): void
    {
        $alert = $event->alert;

        if (! $alert->severity->isAlertWorthy()) {
            return;
        }

        foreach ($this->recipients->forPermission($alert->category->notificationPermission()) as $admin) {
            /** @var User $admin */
            $key = sprintf('operational-alert-recorded:%s:%d', $alert->id, $admin->id);

            $this->idempotency->once($key, OperationalAlertRecordedNotification::class, function () use ($admin, $alert): void {
                $admin->notify(new OperationalAlertRecordedNotification($alert));
            });
        }
    }
}
