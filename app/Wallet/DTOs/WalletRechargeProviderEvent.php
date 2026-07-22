<?php

declare(strict_types=1);

namespace App\Wallet\DTOs;

use App\Wallet\Enums\WalletRechargeProviderEventType;

/**
 * Provider-confirmed settlement data — the only shape
 * WalletRechargeService::processProviderEvent() ever trusts as
 * authoritative. Every field here must already have been verified by
 * the caller (signature check, payload parsing) before this DTO is
 * built; nothing from an unauthenticated browser request is ever
 * placed directly into one of these fields.
 */
final readonly class WalletRechargeProviderEvent
{
    public function __construct(
        public string $provider,
        public string $reference,
        public ?string $providerOrderId,
        public ?string $providerPaymentId,
        public int $amountMinor,
        public string $currencyCode,
        public WalletRechargeProviderEventType $type,
        public ?string $reason = null,
    ) {}
}
