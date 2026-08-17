<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

use App\Wallet\Enums\WalletRechargeOperationalClassification;
use App\Wallet\Enums\WalletRechargeStatus;
use Carbon\CarbonImmutable;

/**
 * One read-only wallet-recharge operational row. Safe references and
 * masked identifiers only — never a client_secret, raw webhook payload,
 * signature, payment-method detail, or unmasked provider identifier.
 *
 * Provider fields are READ FROM the recharge's Payment attempt, never
 * from `wallet_recharges`, which no longer stores any. They are nullable
 * because a recharge legitimately exists before (or without) a payment
 * attempt ever reaching the gateway.
 */
final readonly class WalletRechargeMonitoringRow
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $studentLabel,
        public string $currencyCode,
        public int $amountMinor,
        public ?string $provider,
        public WalletRechargeStatus $status,
        public WalletRechargeOperationalClassification $classification,
        public ?string $failureCode,
        /** payments.paid_at — when the provider confirmed capture. */
        public ?CarbonImmutable $providerConfirmedAtUtc,
        public ?CarbonImmutable $succeededAtUtc,
        public ?CarbonImmutable $failedAtUtc,
        /** payments.last_synced_at — when reconciliation last polled the provider. */
        public ?CarbonImmutable $lastSyncedAtUtc,
        public CarbonImmutable $createdAtUtc,
        public ?string $maskedProviderOrderId,
        public ?string $maskedProviderPaymentId,
    ) {}
}
