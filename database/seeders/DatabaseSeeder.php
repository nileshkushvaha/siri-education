<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DefaultRolesAndUsersSeeder::class,
            CurrencySeeder::class,
            LanguageSeeder::class,
            CountrySeeder::class,
            StateSeeder::class,
            LocalizationPermissionSeeder::class,
            PlatformSettingsPermissionSeeder::class,
            InstructorPermissionSeeder::class,
            BookingTypeSeeder::class,
            BookingPermissionSeeder::class,
            AcademicPermissionSeeder::class,
            AcademicCategorySeeder::class,
            SubjectSeeder::class,
            AcademicLevelSeeder::class,
            SkillLevelSeeder::class,
            InstructorSeeder::class,
        ]);
    }
}
