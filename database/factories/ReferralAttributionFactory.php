<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReferralAttribution;
use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Enums\ReferralAttributionSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralAttribution>
 */
class ReferralAttributionFactory extends Factory
{
    public function definition(): array
    {
        $code = ReferralCode::factory();

        return [
            'referrer_id' => User::factory(),
            'referred_student_id' => User::factory(),
            'referral_code_id' => $code,
            'source' => ReferralAttributionSource::Manual,
            'attributed_at' => now(),
        ];
    }
}
