<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    private const LANGUAGES = [
        ['en', 'English', 'English', 'ltr', 10],
        ['hi', 'Hindi', 'Hindi', 'ltr', 20],
    ];

    public function run(): void
    {
        foreach (self::LANGUAGES as [$code, $name, $nativeName, $direction, $sortOrder]) {
            Language::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'native_name' => $nativeName,
                    'direction' => $direction,
                    'status' => 'active',
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }
}
