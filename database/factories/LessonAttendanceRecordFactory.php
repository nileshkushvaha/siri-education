<?php

namespace Database\Factories;

use App\Lessons\Enums\AttendanceSource;
use App\Models\Lesson;
use App\Models\LessonAttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonAttendanceRecord>
 */
class LessonAttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'booking_id' => function (array $attributes) {
                return Lesson::query()->findOrFail($attributes['lesson_id'])->booking_id;
            },
            'source' => AttendanceSource::ProviderWebhook,
        ];
    }

    public function finalized(): static
    {
        return $this->state(['finalized_at' => now()]);
    }

    public function technicalIssue(): static
    {
        return $this->state(['technical_issue_reported_at' => now()]);
    }
}
