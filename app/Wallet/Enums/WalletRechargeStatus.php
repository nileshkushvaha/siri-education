<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

/**
 * Lifecycle of one gateway wallet-recharge attempt (wallet_recharges
 * row) — distinct from WalletLedgerStatus, which is the ledger entry's
 * own posted/reversed state.
 *
 * pending -> provider_created -> awaiting_confirmation -> credit_pending -> succeeded
 *                                                       \-> credit_failed -> succeeded (retried)
 * provider_created|awaiting_confirmation -> failed|cancelled|expired
 *
 * credit_pending and credit_failed exist because a provider capture and
 * a wallet credit are never the same database operation: a payment can
 * be genuinely captured at the gateway while the wallet credit itself
 * cannot be applied (wallet closed/ownership changed). That money must
 * never be relabeled an ordinary failure — only succeeded/failed/
 * cancelled/expired are truly terminal; credit_pending/credit_failed
 * always remain retryable until they reach succeeded.
 */
enum WalletRechargeStatus: string
{
    case Pending = 'pending';
    case ProviderCreated = 'provider_created';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case CreditPending = 'credit_pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case CreditFailed = 'credit_failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::ProviderCreated => 'Provider Order Created',
            self::AwaitingConfirmation => 'Awaiting Confirmation',
            self::CreditPending => 'Credit Pending',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::CreditFailed => 'Credit Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /** No further transition is possible — money either settled or genuinely never will. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled, self::Expired => true,
            default => false,
        };
    }

    /** A fresh settlement event (browser verify / webhook) may act on a recharge in this state. */
    public function isAwaitingSettlement(): bool
    {
        return match ($this) {
            self::ProviderCreated, self::AwaitingConfirmation => true,
            default => false,
        };
    }

    /** A provider capture is confirmed but the wallet credit has not yet succeeded — must always remain retryable. */
    public function needsCreditRetry(): bool
    {
        return match ($this) {
            self::CreditPending, self::CreditFailed => true,
            default => false,
        };
    }
}
