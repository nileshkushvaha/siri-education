<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Alerts\Services\OperationalAlertService;
use App\Alerts\Support\OperationalAlertActivityMapper;
use App\Events\ActivityCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Requirement #8 — alert-generation failure must never roll back the
 * originating booking/meeting/payment/reconciliation transaction. This
 * listener is queued exactly like `NotifyAdminsOnActivity` (its
 * sibling on the same event), so it only ever runs after the source
 * transaction has already committed; nothing here can unwind it.
 */
final class CreateOperationalAlertOnActivity implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly OperationalAlertActivityMapper $mapper,
        private readonly OperationalAlertService $alerts,
    ) {}

    public function handle(ActivityCreated $event): void
    {
        $signal = $this->mapper->map($event->activity);

        if ($signal === null) {
            return;
        }

        $this->alerts->createOrMerge($signal);
    }
}
