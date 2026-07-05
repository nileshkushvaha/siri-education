<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AcademicLevelSeeder extends Seeder
{
    private const LEVELS = [
        ['name' => 'Primary', 'min_grade' => 1, 'max_grade' => 5],
        ['name' => 'Middle School', 'min_grade' => 6, 'max_grade' => 8],
        ['name' => 'High School', 'min_grade' => 9, 'max_grade' => 12],
        ['name' => 'Undergraduate', 'min_grade' => null, 'max_grade' => null],
        ['name' => 'Postgraduate', 'min_grade' => null, 'max_grade' => null],
        ['name' => 'Professional', 'min_grade' => null, 'max_grade' => null],
    ];

    public function run(): void
    {
        foreach (self::LEVELS as $index => $level) {
            AcademicLevel::query()->firstOrCreate(
                ['slug' => Str::slug($level['name'])],
                [
                    'name' => $level['name'],
                    'min_grade' => $level['min_grade'],
                    'max_grade' => $level['max_grade'],
                    'status' => 'active',
                    'display_order' => $index,
                ],
            );
        }
    }
}
