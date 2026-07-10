<?php

namespace Database\Factories;

use App\Models\AcademicCategory;
use App\Models\Subject;
use App\Models\SubjectTopic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubjectTopic>
 */
class SubjectTopicFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            // Subject has no factory (master data is normally seeded/admin
            // created) — build a minimal active one inline instead.
            'subject_id' => function (): string {
                $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
                $subjectName = ucfirst(fake()->unique()->words(2, true));

                return Subject::create([
                    'academic_category_id' => $category->id,
                    'name' => $subjectName,
                    'slug' => Str::slug($subjectName),
                ])->id;
            },
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
            'display_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }

    public function childOf(SubjectTopic $parent): static
    {
        return $this->state([
            'subject_id' => $parent->subject_id,
            'parent_id' => $parent->id,
        ]);
    }
}
