<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SkillLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SkillLevelSeeder extends Seeder
{
    private const LEVELS = ['Beginner', 'Intermediate', 'Advanced', 'Expert'];

    public function run(): void
    {
        foreach (self::LEVELS as $index => $name) {
            SkillLevel::query()->firstOrCreate(
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
