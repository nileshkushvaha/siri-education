<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\PaymentWebhookEvent;

/** A verified, provider-agnostic webhook notification. */
final readonly class PaymentWebhookData
{
    public function __construct(
        public PaymentWebhookEvent $event,
        public string $reference,
        public ?string $reason = null,
    ) {}
}
