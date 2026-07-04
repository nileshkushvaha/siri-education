<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** Normalized provider webhook events — providers map their own names. */
enum PaymentWebhookEvent: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
