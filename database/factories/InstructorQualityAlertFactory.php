<?php

namespace Database\Factories;

use App\Models\InstructorQualityAlert;
use App\Models\User;
use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Enums\InstructorQualityAlertType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstructorQualityAlert>
 */
class InstructorQualityAlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instructor_id' => User::factory(),
            'alert_type' => InstructorQualityAlertType::SingleLowRating,
            'severity' => InstructorQualityAlertSeverity::Low,
            'status' => InstructorQualityAlertStatus::Open,
            'detection_fingerprint' => (string) Str::uuid(),
            'triggered_at' => now(),
            'threshold_snapshot' => ['low_rating_threshold' => 2],
            'version' => 1,
        ];
    }
}
