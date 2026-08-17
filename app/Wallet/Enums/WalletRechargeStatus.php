<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

/**
 * The CREDIT lifecycle of a wallet recharge — deliberately NOT the
 * payment lifecycle, which belongs to `Payment`/`PaymentStatus`.
 *
 *   requested -> credit_pending -> succeeded
 *                              \-> credit_failed -> succeeded (retried)
 *   requested -> failed | cancelled | expired
 *
 * The provider-shaped states this enum used to carry —
 * `pending`, `provider_created`, `awaiting_confirmation` — are gone.
 * They described where an external charge had got to, which is a fact
 * about a Payment attempt, and holding a second copy here meant the two
 * records could disagree about whether money had arrived. "Has the
 * student paid?" is now answered by the recharge's Payment attempts;
 * this enum answers only "has the wallet been credited?".
 *
 * `credit_pending` and `credit_failed` exist because a provider capture
 * and a wallet credit are never the same database operation: a payment
 * can be genuinely captured while the credit itself cannot be applied
 * (wallet frozen/closed). That money must never be relabelled an
 * ordinary failure — only succeeded/failed/cancelled/expired are truly
 * terminal, and credit_pending/credit_failed always remain retryable
 * until they reach succeeded.
 */
enum WalletRechargeStatus: string
{
    /** The student has asked to add money; a Payment attempt is open. */
    case Requested = 'requested';

    /** The provider captured the money; the ledger credit has not been applied yet. */
    case CreditPending = 'credit_pending';

    case Succeeded = 'succeeded';

    /** Captured by the provider, but the wallet could not be credited. Durable and retryable. */
    case CreditFailed = 'credit_failed';

    /** No payment could be started, or every attempt failed and the student walked away. */
    case Failed = 'failed';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::CreditPending => 'Credit Pending',
            self::Succeeded => 'Succeeded',
            self::CreditFailed => 'Credit Failed',
            self::Failed => 'Failed',
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

    /** A provider capture is confirmed but the wallet credit has not succeeded — must always remain retryable. */
    public function needsCreditRetry(): bool
    {
        return match ($this) {
            self::CreditPending, self::CreditFailed => true,
            default => false,
        };
    }

    /** Still awaiting an authoritative provider outcome. */
    public function isAwaitingPayment(): bool
    {
        return $this === self::Requested;
    }
}
