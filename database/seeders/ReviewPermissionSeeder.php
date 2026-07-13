<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Review-eligibility, submitted-review, and review-report permissions
 * (Filament Shield naming). Idempotent — required after deploy:
 * policies deny unknown permissions, so without this only super_admin
 * can inspect eligibility/review records, and no one but super_admin
 * can report or resolve reports. `Report:LessonReview` (Phase 17M) is
 * the one permission here that is NOT staff-only — it goes to student
 * and instructor, the two frontend-portal roles that browse public
 * profiles; every other permission here stays manager-only.
 */
class ReviewPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:LessonReviewEligibility', 'View:LessonReviewEligibility',
        'ViewAny:LessonReview', 'View:LessonReview',
        'Moderate:LessonReview', 'Hide:LessonReview',
        'ViewAny:ReviewReport', 'View:ReviewReport', 'Resolve:ReviewReport',
    ];

    private const array REPORTER_PERMISSIONS = [
        'Report:LessonReview',
    ];

    private const array REPORTER_ROLES = ['student', 'instructor'];

    public function run(): void
    {
        foreach ([...self::MANAGER_PERMISSIONS, ...self::REPORTER_PERMISSIONS] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Spatie caches its permission collection in-memory; if anything
        // (e.g. an earlier policy check in the same request/test) already
        // primed that cache before the rows above were created,
        // givePermissionTo() below would validate against the stale
        // (permission-less) cache and throw PermissionDoesNotExist even
        // though the rows now exist. Clear it before, not just after.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        foreach (self::REPORTER_ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->givePermissionTo(self::REPORTER_PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
