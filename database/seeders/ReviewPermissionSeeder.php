<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Review-eligibility and submitted-review permissions (Filament Shield
 * naming). Idempotent — required after deploy: policies deny unknown
 * permissions, so without this only super_admin can inspect either.
 * Instructors receive nothing here by design — they never see or alter
 * a student's review eligibility or submitted review.
 */
class ReviewPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:LessonReviewEligibility', 'View:LessonReviewEligibility',
        'ViewAny:LessonReview', 'View:LessonReview',
        'Moderate:LessonReview', 'Hide:LessonReview',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_PERMISSIONS as $permission) {
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
