<?php

namespace Database\Factories;

use App\Models\LessonReview;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewReport>
 */
class ReviewReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_id' => LessonReview::factory(),
            'reporter_id' => User::factory(),
            'reason' => ReviewReportReason::Other,
            'explanation' => 'This review looks suspicious.',
            'status' => ReviewReportStatus::Pending,
            'submitted_at' => now(),
            'version' => 1,
        ];
    }
}
