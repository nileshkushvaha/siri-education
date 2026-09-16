<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Services\BookingSeriesPrepaymentService;
use Illuminate\Console\Command;

/**
 * Safety-net sweep for "pay for all my classes".
 *
 * A student's top-up for a schedule is normally spent on the classes by
 * the browser's verified return or by the queued WalletRechargeSucceeded
 * listener. If neither ran — queue down, job lost, tries exhausted —
 * the money sits in the wallet while the reservations lapse. This finds
 * credited schedule top-ups whose classes are still unpaid and settles
 * them through the one service method every other path uses.
 *
 * Idempotent: per-class row locks and payment_status preconditions make
 * a repeat pass pay nothing twice.
 */
final class SettleSeriesPrepayments extends Command
{
    protected $signature = 'booking:settle-series-prepayments {--limit=200}';

    protected $description = 'Settle schedule classes from top-ups that were paid for them but never applied.';

    public function handle(BookingSeriesPrepaymentService $prepayments): int
    {
        $paid = $prepayments->settleOutstandingRecharges((int) $this->option('limit'));

        $this->info("Settled {$paid} schedule class(es) from outstanding prepayment top-ups.");

        return self::SUCCESS;
    }
}
