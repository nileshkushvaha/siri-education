<?php

namespace Database\Factories;

use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;
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
}
