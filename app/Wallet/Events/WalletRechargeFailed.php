<?php

declare(strict_types=1);

namespace App\Wallet\Events;

use App\Models\WalletRecharge;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A recharge attempt reached a genuine terminal provider failure
 * (failed/cancelled/expired) — never dispatched for a payment that was
 * ever captured; that path is WalletRechargeCreditFailed instead.
 */
final class WalletRechargeFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WalletRecharge $recharge,
    ) {}
}
