<?php

declare(strict_types=1);

namespace App\Wallet\DTOs;

/** Gateway-neutral frontend checkout payload for a just-initiated recharge — never a secret. */
final readonly class WalletRechargeCheckoutData
{
    /** @param  array<string, mixed>  $checkoutPayload */
    public function __construct(
        public string $rechargeId,
        public string $provider,
        public string $reference,
        public int $amountMinor,
        public string $currencyCode,
        public array $checkoutPayload,
    ) {}
}
