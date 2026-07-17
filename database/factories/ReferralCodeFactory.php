<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Enums\ReferralCodeStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralCode>
 */
class ReferralCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('????####')),
            'status' => ReferralCodeStatus::Active,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'status' => ReferralCodeStatus::Disabled,
            'disabled_at' => now(),
            'disable_reason' => 'Disabled in test.',
        ]);
    }
}
