<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Support\CompensationMath;
use Carbon\CarbonImmutable;

/**
 * Immutable result of the instructor compensation resolver. Carries the
 * applied agreement version, rate, and the canonical resolution
 * timestamp (the lesson's scheduled start) so the earning snapshot can
 * be frozen — and, by construction, carries NO student price, payment
 * amount, margin, or discount: those values cannot reach the earning
 * calculation because there is no field for them.
 */
final readonly class CompensationResolution
{
    public function __construct(
        public string $agreementId,
        public string $agreementReference,
        public int $agreementVersion,
        public CompensationPayBasis $payBasis,
        public EarningCalculationType $calculationType,
        public int $rateMinor,
        public int $amountMinor,
        public ?int $currencyId,
        public string $currencyCode,
        public CarbonImmutable $resolvedAt,
        public ?int $eligibleMinutes = null,
        public ?string $overrideId = null,
    ) {}

    /** The immutable calculation snapshot persisted in earning metadata. */
    public function snapshot(): array
    {
        $snapshot = [
            'agreement_id' => $this->agreementId,
            'agreement_reference' => $this->agreementReference,
            'agreement_version' => $this->agreementVersion,
            // The instant the agreement was resolved FOR — the lesson's
            // scheduled start, never the completion/processing time.
            'agreement_effective_timestamp' => $this->resolvedAt->toIso8601String(),
            'pay_basis' => $this->payBasis->value,
            'calculation_type' => $this->calculationType->value,
            'rate_minor' => $this->rateMinor,
            'rounding_policy' => CompensationMath::ROUNDING_POLICY,
            'calculated_amount_minor' => $this->amountMinor,
            'currency_id' => $this->currencyId,
            'currency_code' => $this->currencyCode,
            'calculated_at' => now()->toIso8601String(),
        ];

        // Only the genuinely optional dimensions are omitted when absent.
        if ($this->eligibleMinutes !== null) {
            $snapshot['eligible_minutes'] = $this->eligibleMinutes;
        }

        if ($this->overrideId !== null) {
            $snapshot['override_id'] = $this->overrideId;
        }

        return $snapshot;
    }
}
