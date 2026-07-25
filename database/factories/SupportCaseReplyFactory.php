<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportCase;
use App\Models\SupportCaseReply;
use App\Models\User;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportCaseReply>
 */
class SupportCaseReplyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'support_case_id' => SupportCase::factory(),
            'author_id' => User::factory(),
            'visibility' => SupportCaseReplyVisibility::RequesterVisible,
            'body' => fake()->paragraph(),
        ];
    }

    public function internalNote(): static
    {
        return $this->state(fn (): array => [
            'visibility' => SupportCaseReplyVisibility::InternalNote,
        ]);
    }
}
