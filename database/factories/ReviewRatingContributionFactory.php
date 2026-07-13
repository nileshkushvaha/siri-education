<?php

namespace Database\Factories;

use App\Models\LessonReview;
use App\Models\ReviewRatingContribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewRatingContribution>
 */
class ReviewRatingContributionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_id' => LessonReview::factory(),
            'instructor_id' => User::factory(),
            'included' => false,
            'version' => 1,
        ];
    }
}
