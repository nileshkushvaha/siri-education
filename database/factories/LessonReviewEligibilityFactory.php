<?php

namespace Database\Factories;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Enums\ReviewableLessonType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonReviewEligibility>
 */
class LessonReviewEligibilityFactory extends Factory
{
    public function definition(): array
    {
        $lesson = Lesson::factory()->create();

        return [
            'lesson_id' => $lesson->id,
            'booking_id' => $lesson->booking_id,
            'student_id' => $lesson->student_id,
            'instructor_id' => $lesson->instructor_id,
            'lesson_type' => ReviewableLessonType::Paid,
            'eligibility_mode' => LessonReviewEligibilityMode::PublicReview,
            'status' => LessonReviewEligibilityStatus::Open,
            'opens_at' => now(),
            'expires_at' => now()->addDays(14),
            'outcome_snapshot' => LessonOutcome::Completed->value,
            'source_outcome_version' => 1,
            'version' => 1,
            'metadata' => [
                'reviews_enabled' => true,
                'paid_lesson_reviews_enabled' => true,
                'demo_review_policy' => 'private_only',
                'review_window_days' => 14,
            ],
        ];
    }

    public function used(): static
    {
        return $this->state([
            'status' => LessonReviewEligibilityStatus::Used,
            'used_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => LessonReviewEligibilityStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(string $reason = 'Outcome corrected.'): static
    {
        return $this->state([
            'status' => LessonReviewEligibilityStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }

    public function forStudent(User $student): static
    {
        return $this->state(['student_id' => $student->id]);
    }
}
