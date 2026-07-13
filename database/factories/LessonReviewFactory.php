<?php

namespace Database\Factories;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonReview>
 */
class LessonReviewFactory extends Factory
{
    public function definition(): array
    {
        $eligibility = LessonReviewEligibility::factory()->create();

        return [
            'eligibility_id' => $eligibility->id,
            'lesson_id' => $eligibility->lesson_id,
            'booking_id' => $eligibility->booking_id,
            'student_id' => $eligibility->student_id,
            'instructor_id' => $eligibility->instructor_id,
            'review_mode' => LessonReviewEligibilityMode::PublicReview,
            'overall_rating' => 5,
            'content' => 'Great lesson, would recommend.',
            'status' => StudentReviewStatus::Submitted,
            'submitted_at' => now(),
            'settings_snapshot' => [
                'rating_min' => 1,
                'rating_max' => 5,
                'written_review_required' => false,
                'review_min_length' => 10,
                'review_max_length' => 2000,
                'rating_dimensions_enabled' => true,
                'review_max_tags' => 5,
            ],
            'version' => 1,
        ];
    }

    public function private(): static
    {
        return $this->state(['review_mode' => LessonReviewEligibilityMode::PrivateFeedback, 'status' => StudentReviewStatus::Private]);
    }

    public function flagged(): static
    {
        return $this->state(['status' => StudentReviewStatus::Flagged]);
    }

    public function forEligibility(LessonReviewEligibility $eligibility): static
    {
        return $this->state([
            'eligibility_id' => $eligibility->id,
            'lesson_id' => $eligibility->lesson_id,
            'booking_id' => $eligibility->booking_id,
            'student_id' => $eligibility->student_id,
            'instructor_id' => $eligibility->instructor_id,
        ]);
    }
}
