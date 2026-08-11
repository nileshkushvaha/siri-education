<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AcademicStatus;
use App\Models\EducationSystem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EducationSystem>
 */
class EducationSystemFactory extends Factory
{
    protected $model = EducationSystem::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'description' => fake()->sentence(),
            'status' => AcademicStatus::Active->value,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => AcademicStatus::Inactive->value]);
    }
}
