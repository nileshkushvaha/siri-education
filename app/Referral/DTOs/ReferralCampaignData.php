<?php

declare(strict_types=1);

namespace App\Referral\DTOs;

use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use DateTimeImmutable;

/**
 * Validated campaign configuration handed to ReferralCampaignService.
 * All money/percentage values are integers: basis points for
 * percentage campaigns, minor units for fixed campaigns.
 *
 * @param  list<int>  $eligibleCountryIds  empty list = all countries
 */
final readonly class ReferralCampaignData
{
    public function __construct(
        public string $name,
        public ?string $description,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ReferralRewardType $rewardType,
        public int $rewardValue,
        public ?string $rewardCurrencyCode,
        public int $minCompletedPaidLessons,
        public int $maxRewardedClasses,
        public ReferralRewardTiming $rewardTiming,
        public int $holdDays,
        public bool $requiresFraudReview,
        public ?string $terms,
        public array $eligibleCountryIds = [],
    ) {}
}
