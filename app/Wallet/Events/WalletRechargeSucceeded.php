<?php

declare(strict_types=1);

namespace App\Wallet\Events;

use App\Models\WalletRecharge;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A provider-verified wallet recharge was credited. Fires at most once
 * per recharge — the ledger idempotency key guarantees a single
 * credit, and WalletRechargeService only dispatches this when the
 * recharge's status actually transitioned to Succeeded this call.
 */
final class WalletRechargeSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WalletRecharge $recharge,
    ) {}
}
