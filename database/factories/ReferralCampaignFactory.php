<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use App\Models\ReferralCampaign;
use App\Referral\Enums\ReferralCampaignStatus;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralCampaign>
 */
class ReferralCampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Campaign '.$this->faker->unique()->word(),
            'description' => null,
            'status' => ReferralCampaignStatus::Draft,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'reward_type' => ReferralRewardType::Percentage,
            'reward_value' => 500,
            'reward_currency_id' => null,
            'reward_currency_code' => null,
            'min_completed_paid_lessons' => 1,
            'max_rewarded_classes' => 10,
            'reward_timing' => ReferralRewardTiming::Immediate,
            'hold_days' => 0,
            'requires_fraud_review' => false,
            'terms' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => ReferralCampaignStatus::Active]);
    }

    public function fixed(string $currencyCode = 'INR', int $amountMinor = 10000): static
    {
        return $this->state(function () use ($currencyCode, $amountMinor): array {
            $currency = Currency::query()->where('code', $currencyCode)->first();

            return [
                'reward_type' => ReferralRewardType::Fixed,
                'reward_value' => $amountMinor,
                'reward_currency_id' => $currency?->id,
                'reward_currency_code' => $currencyCode,
            ];
        });
    }
}
