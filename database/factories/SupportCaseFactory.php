<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportCase;
use App\Models\User;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Enums\SupportCaseType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportCase>
 */
class SupportCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'case_number' => 'SUP-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'type' => SupportCaseType::Student,
            'category' => SupportCaseCategory::Booking,
            'priority' => SupportCasePriority::Medium,
            'status' => SupportCaseStatus::Open,
            'created_by' => User::factory(),
            'student_id' => null,
            'instructor_id' => null,
            'assigned_to' => null,
            'linked_record_type' => null,
            'linked_record_id' => null,
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'opened_at' => now(),
        ];
    }

    public function forStudent(?User $student = null): static
    {
        $student ??= User::factory()->create();

        return $this->state(fn (): array => [
            'type' => SupportCaseType::Student,
            'created_by' => $student->id,
            'student_id' => $student->id,
        ]);
    }

    public function forInstructor(?User $instructor = null): static
    {
        $instructor ??= User::factory()->create();

        return $this->state(fn (): array => [
            'type' => SupportCaseType::Instructor,
            'created_by' => $instructor->id,
            'instructor_id' => $instructor->id,
        ]);
    }
}
