<?php

declare(strict_types=1);

namespace App\Referral\DTOs;

use App\Referral\Enums\ReferralRewardType;

/**
 * The single calculation owner's output — pure integers. Percentage:
 * floor(lesson_amount_minor × basis_points / 10000) in the lesson's
 * own currency. Fixed: the campaign's stored minor units in the
 * campaign's own currency. amountMinor === 0 is a valid result the
 * caller must turn into a terminal Rejected reward, never a credit.
 */
final readonly class ReferralRewardCalculationResult
{
    public function __construct(
        public ReferralRewardType $rewardType,
        public int $rewardValue,
        public int $amountMinor,
        public string $currencyCode,
    ) {}
}
