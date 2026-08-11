<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Curriculum;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Curriculum>
 */
class CurriculumFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'subject_id' => function (): string {
                $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
                $subjectName = ucfirst(fake()->unique()->words(2, true));

                return Subject::create([
                    'academic_category_id' => $category->id,
                    'name' => $subjectName,
                    'slug' => Str::slug($subjectName),
                ])->id;
            },
            'academic_level_id' => function (): string {
                $levelName = ucfirst(fake()->unique()->words(2, true));

                return AcademicLevel::query()->create([
                    'name' => $levelName,
                    'slug' => Str::slug($levelName).'-'.fake()->unique()->numberBetween(1000, 999999),
                ])->id;
            },
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
        ];
    }
}
