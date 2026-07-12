<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** Normalized provider webhook events — providers map their own names. */
enum PaymentWebhookEvent: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /** An async capture is underway (e.g. Stripe payment_intent.processing) — not yet actionable, never Succeeded/Failed. */
    case Processing = 'processing';

    /** Verified but not actionable (e.g. payment.authorized) — acknowledge, do not process. */
    case Ignored = 'ignored';
}
