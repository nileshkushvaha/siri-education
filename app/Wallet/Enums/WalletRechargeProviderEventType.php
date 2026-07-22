<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

/**
 * Normalized provider event for a wallet recharge — kept distinct from
 * App\Booking\Enums\PaymentWebhookEvent so the wallet domain never
 * depends on the booking-payment namespace for its own vocabulary.
 */
enum WalletRechargeProviderEventType: string
{
    case Captured = 'captured';
    case Failed = 'failed';

    /** An async capture is underway — not yet actionable, never Captured/Failed. */
    case Processing = 'processing';

    /** Verified but not actionable for a recharge — acknowledge, do not process. */
    case Ignored = 'ignored';
}
