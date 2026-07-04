<?php

namespace Database\Factories;

use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeacherSubject>
 */
class TeacherSubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teacher_id' => User::factory(),
            'subject' => fake()->randomElement(['maths', 'english', 'science', 'history']),
            'grade_from' => 1,
            'grade_to' => 12,
        ];
    }

    public function subject(string $subject, ?int $from = null, ?int $to = null): static
    {
        return $this->state([
            'subject' => $subject,
            'grade_from' => $from,
            'grade_to' => $to,
        ]);
    }
}
