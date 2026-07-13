<?php

namespace Database\Factories;

use App\Models\InstructorRatingAggregate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorRatingAggregate>
 */
class InstructorRatingAggregateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instructor_id' => User::factory(),
            'eligible_review_count' => 0,
            'overall_rating_sum' => 0,
            'rating_distribution' => [],
            'paid_review_count' => 0,
            'demo_review_count' => 0,
            'version' => 1,
        ];
    }
}
