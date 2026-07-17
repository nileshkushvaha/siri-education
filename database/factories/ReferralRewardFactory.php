<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Lesson;
use App\Models\ReferralAttribution;
use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Enums\ReferralRewardType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralReward>
 *
 * Deliberately relation-light: tests build the real booking/lesson
 * chain through domain factories and pass ids in — this factory only
 * fills scalar snapshot defaults.
 */
class ReferralRewardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attribution_id' => ReferralAttribution::factory(),
            'campaign_id' => ReferralCampaign::factory(),
            'referrer_id' => User::factory(),
            'referred_student_id' => User::factory(),
            'lesson_id' => Lesson::factory(),
            'booking_id' => Booking::factory(),
            'class_sequence' => 1,
            'lesson_amount_minor' => 50000,
            'lesson_currency_code' => 'INR',
            'reward_type' => ReferralRewardType::Percentage,
            'reward_value' => 500,
            'reward_amount_minor' => 2500,
            'reward_currency_code' => 'INR',
            'status' => ReferralRewardStatus::Eligible,
            'eligible_at' => now(),
            'credit_ready_at' => now(),
        ];
    }
}
