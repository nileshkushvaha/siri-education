<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MessagingRestriction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessagingRestriction>
 */
class MessagingRestrictionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'applied_by' => User::factory(),
            'reason' => 'Policy violation.',
            'applied_at' => now(),
        ];
    }

    public function lifted(): static
    {
        return $this->state(fn (): array => [
            'lifted_at' => now(),
            'lifted_by' => User::factory(),
            'lifted_reason' => 'Restriction lifted after review.',
        ]);
    }
}
