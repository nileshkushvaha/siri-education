<?php

namespace Database\Factories;

use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = Carbon::instance(fake()->dateTimeBetween('+1 day', '+30 days'))->startOfHour();

        return [
            'booking_id' => Booking::factory()->confirmed(),
            'student_id' => User::factory(),
            'instructor_id' => User::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
            'timezone' => 'UTC',
            'status' => LessonStatus::Scheduled,
            'student_attendance_status' => LessonAttendanceStatus::Pending,
            'instructor_attendance_status' => LessonAttendanceStatus::Pending,
        ];
    }

    /** Lesson whose end time (plus any grace period) is already in the past. */
    public function endedHoursAgo(int $hours = 48): static
    {
        return $this->state(function () use ($hours): array {
            $endsAt = now()->subHours($hours)->startOfHour();

            return [
                'starts_at' => $endsAt->copy()->subMinutes(60),
                'ends_at' => $endsAt,
            ];
        });
    }

    public function live(): static
    {
        return $this->state(['status' => LessonStatus::Live]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => LessonStatus::Completed,
            'completed_at' => now(),
            'student_attendance_status' => LessonAttendanceStatus::Attended,
            'instructor_attendance_status' => LessonAttendanceStatus::Attended,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => LessonStatus::Cancelled]);
    }
}
