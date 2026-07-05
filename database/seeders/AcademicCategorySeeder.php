<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AcademicCategorySeeder extends Seeder
{
    private const CATEGORIES = [
        'Mathematics',
        'Sciences',
        'Languages',
        'Computer Science',
        'Test Prep',
        'Arts and Humanities',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => $name) {
            AcademicCategory::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'display_order' => $index,
                    'is_active' => true,
                ],
            );
        }
    }
}
