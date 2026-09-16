<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Services\BookingSeriesPrepaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Finishes the payment the student started.
 *
 * A student who chose "pay for all my classes" is topped up for exactly
 * that amount and then has the classes settled from the balance. Doing
 * the second half here rather than on the browser's return is what makes
 * it reliable: closing the tab at the gateway, a flaky redirect, or a
 * webhook arriving before the redirect all end the same way, instead of
 * leaving classes unpaid until their reservations lapse.
 *
 * This is NOT automatic charging. It runs only for a recharge the
 * student raised through the prepayment flow, for the exact classes
 * named on it, for the exact amount they were quoted. An ordinary
 * top-up carries no such marker and is never spent on their behalf.
 */
final class SettleSeriesPrepaymentOnWalletRechargeSucceeded implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        private readonly BookingSeriesPrepaymentService $prepayments,
    ) {}

    public function handle(object $event): void
    {
        // Purpose check, ownership, re-quote and partial-failure logging
        // all live in the service — the browser return and the scheduled
        // sweep run the very same method, so the three can never disagree.
        $this->prepayments->settleForRecharge($event->recharge);
    }
}
