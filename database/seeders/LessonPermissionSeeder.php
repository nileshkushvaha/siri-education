<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lesson module permissions (Filament Shield naming). Idempotent —
 * required after deploy: policies fall back to "deny" for permissions
 * that do not exist, so without this only super_admin can reach the
 * lessons admin.
 */
class LessonPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Everything except force-delete — that stays super_admin-only. */
    private const array MANAGER_PERMISSIONS = [
        'ViewAny:Lesson', 'View:Lesson', 'Update:Lesson', 'Delete:Lesson', 'Restore:Lesson',
        'MarkAttendance:Lesson', 'Complete:Lesson', 'Cancel:Lesson', 'Dispute:Lesson',
    ];

    private const array SUPER_ONLY_PERMISSIONS = [
        'ForceDelete:Lesson',
    ];

    public function run(): void
    {
        foreach ([...self::MANAGER_PERMISSIONS, ...self::SUPER_ONLY_PERMISSIONS] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
