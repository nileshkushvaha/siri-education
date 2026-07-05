<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicCategory;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubjectSeeder extends Seeder
{
    private const SUBJECTS_BY_CATEGORY = [
        'Mathematics' => ['Algebra', 'Geometry', 'Calculus', 'Statistics'],
        'Sciences' => ['Physics', 'Chemistry', 'Biology'],
        'Languages' => ['English', 'Spanish', 'French', 'Mandarin'],
        'Computer Science' => ['Programming Fundamentals', 'Web Development', 'Data Structures & Algorithms'],
        'Test Prep' => ['SAT Prep', 'IELTS Prep', 'TOEFL Prep'],
        'Arts and Humanities' => ['History', 'Music Theory', 'Creative Writing'],
    ];

    public function run(): void
    {
        foreach (self::SUBJECTS_BY_CATEGORY as $categoryName => $subjects) {
            $category = AcademicCategory::query()->where('slug', Str::slug($categoryName))->first();

            if (! $category) {
                continue;
            }

            foreach ($subjects as $index => $name) {
                Subject::query()->firstOrCreate(
                    ['slug' => Str::slug($name)],
                    [
                        'academic_category_id' => $category->id,
                        'name' => $name,
                        'status' => 'active',
                        'display_order' => $index,
                    ],
                );
            }
        }
    }
}
