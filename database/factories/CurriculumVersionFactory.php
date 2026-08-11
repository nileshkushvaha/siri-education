<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumVersion>
 */
class CurriculumVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'curriculum_id' => fn (): string => Curriculum::factory()->create()->id,
            'version_number' => 1,
            'status' => CurriculumVersionStatus::Draft->value,
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status' => CurriculumVersionStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state([
            'status' => CurriculumVersionStatus::Archived->value,
            'published_at' => now()->subMonth(),
            'archived_at' => now(),
        ]);
    }
}
