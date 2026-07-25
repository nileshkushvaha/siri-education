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
            InstructorDocumentRequirementPermissionSeeder::class,
            StudentPermissionSeeder::class,
            StudentLifecyclePermissionSeeder::class,
            BookingTypeSeeder::class,
            BookingPermissionSeeder::class,
            BookingPaymentPermissionSeeder::class,
            // Phase 17U.2 §11 — wired into the normal deploy path so
            // managers can reach lesson/review/feedback admin surfaces
            // without a manual `db:seed --class=X`. Order follows the
            // domain dependency chain (Booking -> Lesson -> Review ->
            // Feedback); all four are idempotent (Permission::firstOrCreate
            // + forgetCachedPermissions), safe to run on every deploy.
            LessonPermissionSeeder::class,
            ReviewPermissionSeeder::class,
            FeedbackPermissionSeeder::class,
            WalletPermissionSeeder::class,
            ReferralPermissionSeeder::class,
            // Phase 30 — reads Auth/Booking/Referral/Wallet signals, so
            // placed after all four domains it monitors.
            SuspiciousActivityFlagPermissionSeeder::class,
            QueueMonitorPermissionSeeder::class,
            PulsePermissionSeeder::class,
            // Phase 18B — the reporting-foundation permission set. No data
            // dependency of its own; placed after every domain it reports
            // on so it can be reasoned about as "reads everything above".
            ReportingPermissionSeeder::class,
            AcademicPermissionSeeder::class,
            AcademicCategorySeeder::class,
            SubjectSeeder::class,
            StudentLessonPriceSeeder::class,
            AcademicLevelSeeder::class,
            SkillLevelSeeder::class,
            InstructorSeeder::class,
            InstructorDocumentRequirementSeeder::class,
            FaqSeeder::class,
        ]);
    }
}
