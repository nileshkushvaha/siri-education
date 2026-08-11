<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\InstructorCurriculumEligibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorCurriculumEligibility>
 */
class InstructorCurriculumEligibilityFactory extends Factory
{
    protected $model = InstructorCurriculumEligibility::class;

    public function definition(): array
    {
        return [
            'teacher_id' => User::factory(),
            'education_system_id' => EducationSystem::factory(),
            'curriculum_id' => Curriculum::factory(),
            'is_active' => true,
            'notes' => null,
            'approved_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function unapproved(): static
    {
        return $this->state(['approved_at' => null, 'approved_by' => null]);
    }
}
