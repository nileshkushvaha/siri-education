<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCampaigns\Pages\Concerns;

use App\Referral\DTOs\ReferralCampaignData;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * Translates the validated Filament form state into the service DTO —
 * the pages never write the model themselves, so ReferralCampaignService
 * stays the single writer and auditor of campaign rules.
 */
trait BuildsCampaignData
{
    /** @param  array<string, mixed>  $data */
    private function campaignDataFrom(array $data): ReferralCampaignData
    {
        $rewardType = ReferralRewardType::from($data['reward_type']);
        $rewardTiming = ReferralRewardTiming::from($data['reward_timing']);

        return new ReferralCampaignData(
            name: (string) $data['name'],
            description: filled($data['description'] ?? null) ? (string) $data['description'] : null,
            startsAt: DateTimeImmutable::createFromInterface(Carbon::parse($data['starts_at'], 'UTC')),
            endsAt: DateTimeImmutable::createFromInterface(Carbon::parse($data['ends_at'], 'UTC')),
            rewardType: $rewardType,
            rewardValue: (int) $data['reward_value'],
            rewardCurrencyCode: $rewardType === ReferralRewardType::Fixed ? (string) $data['reward_currency_code'] : null,
            minCompletedPaidLessons: (int) $data['min_completed_paid_lessons'],
            maxRewardedClasses: (int) $data['max_rewarded_classes'],
            rewardTiming: $rewardTiming,
            holdDays: $rewardTiming === ReferralRewardTiming::AfterHoldDays ? (int) ($data['hold_days'] ?? 0) : 0,
            requiresFraudReview: (bool) ($data['requires_fraud_review'] ?? false),
            terms: filled($data['terms'] ?? null) ? (string) $data['terms'] : null,
            eligibleCountryIds: array_map('intval', $data['eligible_countries'] ?? []),
        );
    }
}
