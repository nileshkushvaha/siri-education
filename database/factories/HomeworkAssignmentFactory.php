<?php

namespace Database\Factories;

use App\Homework\Enums\HomeworkStatus;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeworkAssignment>
 */
class HomeworkAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            // The homework context CHECK constraint
            // requires booking_id OR learning_plan_id; default to a
            // completed lesson so bare factory rows stay valid.
            'booking_id' => Booking::factory()->completed(),
            'teacher_id' => User::factory(),
            'student_id' => User::factory(),
            'subject' => fake()->randomElement(['maths', 'english', 'science', 'history']),
            'title' => ucfirst(fake()->words(3, true)),
            'description' => fake()->optional()->sentence(12),
            'due_at' => fake()->dateTimeBetween('now', '+2 weeks'),
            'status' => HomeworkStatus::Pending,
        ];
    }

    public function submitted(): static
    {
        return $this->state([
            'status' => HomeworkStatus::Submitted,
            'submission_text' => fake()->paragraph(),
            'submitted_at' => now(),
        ]);
    }

    public function graded(string $grade = 'A'): static
    {
        return $this->state([
            'status' => HomeworkStatus::Graded,
            'submission_text' => fake()->paragraph(),
            'submitted_at' => now()->subDay(),
            'grade' => $grade,
            'feedback' => fake()->sentence(10),
        ]);
    }

    public function overdue(): static
    {
        return $this->state([
            'status' => HomeworkStatus::Pending,
            'due_at' => now()->subDays(3),
        ]);
    }

    /** Link to a specific completed lesson, inheriting its student/instructor pair. */
    public function forBooking(Booking $booking): static
    {
        return $this->state([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'teacher_id' => $booking->instructor_id,
        ]);
    }

    /** Plan-level homework: no lesson link, pair inherited from the plan. */
    public function forLearningPlan(StudentLearningPlan $plan): static
    {
        return $this->state([
            'booking_id' => null,
            'learning_plan_id' => $plan->id,
            'student_id' => $plan->student_user_id,
            'teacher_id' => $plan->primary_instructor_user_id,
        ]);
    }
}
