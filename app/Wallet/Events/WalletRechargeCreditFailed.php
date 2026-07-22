<?php

declare(strict_types=1);

namespace App\Wallet\Events;

use App\Models\WalletRecharge;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Operational event: the provider genuinely captured this recharge,
 * but the wallet credit itself could not be applied (wallet closed,
 * or ownership/currency no longer match). The money is not lost — the
 * recharge stays CreditFailed for reconciliation's retry — but this
 * needs an operator's attention, distinct from an ordinary checkout
 * failure where no money ever moved.
 */
final class WalletRechargeCreditFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WalletRecharge $recharge,
    ) {}
}
