<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Currency;
use Illuminate\Support\Facades\DB;

/**
 * The smallest governed seam needed to
 * coordinate an admin's currency-status change against a concurrent
 * BookingPaymentService::initiate() call for the same currency code.
 * Both sides lock the same Currency row inside a DB transaction, so
 * MySQL serializes the two: whichever commits first wins, and the
 * loser observes the winner's already-committed state — never both
 * succeeding, never a new payment attempt created moments after (or
 * concurrently with) the currency being disabled.
 *
 * Deliberately does not redesign the Currency/Country module — this
 * is the one write path (the Filament edit page) routed through a
 * lock, not a new domain layer.
 */
final class CurrencyStatusService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Currency $currency, array $data): Currency
    {
        return DB::transaction(function () use ($currency, $data): Currency {
            $locked = Currency::query()->whereKey($currency->id)->lockForUpdate()->firstOrFail();

            $locked->fill($data)->save(); // Currency::LogsActivity auto-audits the status change.

            return $locked->refresh();
        });
    }
}
