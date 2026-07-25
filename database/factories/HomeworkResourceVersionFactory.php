<?php

namespace Database\Factories;

use App\Models\HomeworkResource;
use App\Models\HomeworkResourceVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeworkResourceVersion>
 */
class HomeworkResourceVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'homework_resource_id' => HomeworkResource::factory(),
            'version_number' => 1,
            'created_by' => User::factory(),
            'published_at' => now(),
        ];
    }
}
