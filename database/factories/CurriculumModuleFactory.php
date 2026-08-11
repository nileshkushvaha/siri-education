<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CurriculumModule;
use App\Models\CurriculumVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumModule>
 */
class CurriculumModuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'curriculum_version_id' => fn (): string => CurriculumVersion::factory()->create()->id,
            'title' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(),
            'sort_order' => 0,
        ];
    }
}
