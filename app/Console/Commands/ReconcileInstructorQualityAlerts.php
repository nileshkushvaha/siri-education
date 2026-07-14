<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Quality\Actions\ReconcileInstructorQualityAlertsAction;
use Illuminate\Console\Command;

/**
 * Phase 17N — repair/reconciliation tool, not the primary update path
 * (that's the event-driven detectors). Re-evaluates recent published
 * reviews and finalized lesson outcomes, creating any missing alerts
 * and flagging invalidated signals for review without deleting
 * history. Idempotent, disabled-safe. Deliberately NOT scheduled —
 * run manually after suspected drift or a settings/data correction.
 */
class ReconcileInstructorQualityAlerts extends Command
{
    protected $signature = 'reviews:reconcile-quality-alerts';

    protected $description = 'Re-evaluate recent reviews and lesson outcomes and create any missing instructor quality alerts';

    public function handle(ReconcileInstructorQualityAlertsAction $reconcile): int
    {
        $result = $reconcile->execute();

        $this->info(sprintf(
            'Reconciled quality alerts: %d review(s), %d lesson outcome(s) re-evaluated.',
            $result['reviews_processed'],
            $result['lessons_processed'],
        ));

        return self::SUCCESS;
    }
}
