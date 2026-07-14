<?php

namespace Database\Factories;

use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorStudentFeedback>
 */
class InstructorStudentFeedbackFactory extends Factory
{
    public function definition(): array
    {
        $lesson = Lesson::factory()->completed();

        return [
            'lesson_id' => $lesson,
            'booking_id' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])?->booking_id,
            'student_id' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])?->student_id,
            'instructor_id' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])?->instructor_id,
            'outcome_snapshot' => LessonOutcome::Completed,
            'source_outcome_version' => 1,
            'attendance_status_snapshot' => LessonAttendanceStatus::Attended,
            'engagement_observation' => 'Actively participated throughout the lesson.',
            'submitted_at' => now(),
            'version' => 1,
        ];
    }
}
