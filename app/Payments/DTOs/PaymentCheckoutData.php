<?php

declare(strict_types=1);

namespace App\Payments\DTOs;

/**
 * The gateway-neutral result of opening (or resuming) checkout for a
 * generic Payable. Mirrors WalletRechargeCheckoutData's shape and its
 * rule: `checkoutPayload` is frontend-facing and must never contain a
 * secret key — only the single-use, publishable values a browser
 * legitimately needs.
 */
final readonly class PaymentCheckoutData
{
    /**
     * @param  bool  $resumed  true when this re-presents an already-open attempt rather than creating a new one
     * @param  array<string, mixed>  $checkoutPayload
     */
    public function __construct(
        public string $paymentId,
        public string $provider,
        public string $reference,
        public int $amountMinor,
        public string $currencyCode,
        public array $checkoutPayload,
        public bool $resumed = false,
    ) {}
}
