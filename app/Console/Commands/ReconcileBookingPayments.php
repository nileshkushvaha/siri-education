<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use Illuminate\Console\Command;

/**
 * Scheduled reconciliation sweep for the collection domain
 * (mirrors ReconcileInstructorPayouts on the payout side). Gated by
 * booking_payment_reconciliation_enabled inside the service; idempotent
 * — every state transition it makes reuses
 * BookingPaymentService::applyProviderStatus(), so a duplicate/
 * overlapping run can never apply a financial effect twice.
 */
final class ReconcileBookingPayments extends Command
{
    protected $signature = 'booking-payments:reconcile {--limit=200}';

    protected $description = 'Poll due booking payment attempts for a confirmed provider outcome and resolve mismatches.';

    public function handle(BookingPaymentReconciliationServiceInterface $reconciliation): int
    {
        $examined = $reconciliation->reconcileDue((int) $this->option('limit'));

        $this->info("Reconciliation examined {$examined} due booking payment attempt(s).");

        return self::SUCCESS;
    }
}
