<?php

declare(strict_types=1);

namespace App\Wallet\Events;

use App\Models\LessonFinancialDisposition;
use App\Models\WalletLedgerEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lesson-outcome wallet refund was credited (Phase 17F). Fires at
 * most once per disposition (the ledger idempotency key guarantees a
 * single credit), only after the transaction commits. No listeners are
 * attached in this phase — notifications belong to a later one.
 */
final class LessonRefundCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonFinancialDisposition $disposition,
        public readonly WalletLedgerEntry $entry,
    ) {}
}
