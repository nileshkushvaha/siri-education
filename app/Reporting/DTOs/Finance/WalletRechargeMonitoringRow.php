<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

use App\Wallet\Enums\WalletRechargeOperationalClassification;
use App\Wallet\Enums\WalletRechargeStatus;
use Carbon\CarbonImmutable;

/**
 * One read-only wallet-recharge operational row. Safe
 * references and masked identifiers only — never a client_secret, raw
 * webhook payload, signature, payment-method detail, or unmasked
 * provider identifier.
 */
final readonly class WalletRechargeMonitoringRow
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $studentLabel,
        public string $currencyCode,
        public int $amountMinor,
        public string $provider,
        public WalletRechargeStatus $status,
        public WalletRechargeOperationalClassification $classification,
        public ?string $failureCode,
        public ?CarbonImmutable $providerConfirmedAtUtc,
        public ?CarbonImmutable $succeededAtUtc,
        public ?CarbonImmutable $failedAtUtc,
        public ?CarbonImmutable $lastSyncedAtUtc,
        public CarbonImmutable $createdAtUtc,
        public ?string $maskedProviderOrderId,
        public ?string $maskedProviderPaymentId,
    ) {}
}
