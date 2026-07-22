<?php

declare(strict_types=1);

namespace App\Wallet\DTOs;

use App\Models\WalletRecharge;

/**
 * Outcome of one settlement attempt. credited=true only when a new
 * RechargeConfirmed ledger entry was posted this call; ignored=true
 * for an idempotent repeat (already succeeded) or an out-of-state
 * event that was safely acknowledged without changing anything.
 */
final readonly class WalletRechargeSettlementResult
{
    public function __construct(
        public WalletRecharge $recharge,
        public bool $credited,
        public bool $ignored,
        public ?string $reason = null,
    ) {}
}
