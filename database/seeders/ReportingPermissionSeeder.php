<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 18B — the reporting-foundation permission set. Category-level
 * `View*Reports` permissions gate landing-page section visibility
 * (`ReportCategory::requiredPermission()`); `ViewInstructorCompensationReports`
 * is seeded independently of `ViewFinanceReports` — deliberately not a
 * subset relationship — so instructor-compensation reporting stays
 * more restrictive than general financial reporting even though both
 * are granted to the same `manager` role today, exactly the same
 * precedent `InstructorCompensationPermissionSeeder` already
 * established for the underlying compensation-agreement permissions
 * (no distinct "finance" role exists in this codebase to split them
 * onto). `Export*Reports` permissions are independent of every `View*`
 * permission — view never implies export.
 */
class ReportingPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewExecutiveReports',
        'ViewOperationalReports',
        'ViewStudentReports',
        'ViewInstructorReports',
        'ViewBookingLessonReports',
        'ViewMeetingReports',
        'ViewLearningReports',
        'ViewFinanceReports',
        'ViewWalletReports',
        'ViewPaymentReports',
        'ViewInstructorCompensationReports',
        'ViewReferralReports',
        'ViewMarketplaceReports',
        'ViewReviewQualityReports',
        'ViewNotificationReports',
        'ExportReports',
        'ExportFinancialReports',
        'ExportSensitiveReports',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // See ReviewPermissionSeeder for why this is called both before
        // and after: a policy/permission check earlier in the same
        // request/test can prime Spatie's in-memory cache before the
        // rows above exist, and givePermissionTo() below would then
        // validate against that stale cache.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
