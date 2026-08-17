<?php

declare(strict_types=1);

namespace App\Wallet\DTOs;

use App\Models\Payment;
use App\Models\WalletRecharge;

/**
 * The outcome of one wallet-recharge settlement attempt.
 *
 * Mirrors PackageSettlementResult, with one case packages deliberately
 * do not have: `creditFailed`.
 *
 * A package activation has no business-level failure — its only
 * realistic failures are transient, so rollback-and-retry is the right
 * answer and a durable "paid but not activated" state would be a stuck
 * state nothing can legitimately produce. Crediting a wallet is
 * different: a frozen or closed wallet is a real, persistent refusal.
 * The external money is genuinely collected, so the Payment must stay
 * Paid; the wallet credit has genuinely not happened, so the recharge
 * must stay visible and retryable. `creditFailed` is that state, and it
 * is NOT an error the provider should retry — retrying the webhook
 * would not unfreeze the wallet.
 */
final readonly class WalletRechargeSettlementResult
{
    private function __construct(
        public ?Payment $payment,
        public ?WalletRecharge $recharge,
        public bool $settled,
        public bool $replayed,
        public bool $creditFailed,
        public bool $ignored,
        public ?string $reason,
    ) {}

    /** Payment captured AND the wallet credited, in one transaction. */
    public static function settled(Payment $payment, WalletRecharge $recharge): self
    {
        return new self($payment, $recharge, true, false, false, false, null);
    }

    /** Already fully settled by an earlier delivery of the same event. */
    public static function replayed(Payment $payment, WalletRecharge $recharge): self
    {
        return new self($payment, $recharge, false, true, false, false, 'Already settled.');
    }

    /**
     * The money is collected and the Payment is Paid, but the wallet
     * could not be credited. Durable and retryable — never reported to
     * the provider as a failure, because the provider has nothing left
     * to do.
     */
    public static function creditFailed(Payment $payment, WalletRecharge $recharge, string $reason): self
    {
        return new self($payment, $recharge, false, false, true, false, $reason);
    }

    public static function ignored(?Payment $payment, ?WalletRecharge $recharge, string $reason): self
    {
        return new self($payment, $recharge, false, false, false, true, $reason);
    }
}
