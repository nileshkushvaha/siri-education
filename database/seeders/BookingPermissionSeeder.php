<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Booking module permissions (Filament Shield naming). Idempotent —
 * required after deploy: policies fall back to "deny" for permissions
 * that do not exist, so without this only super_admin can reach the
 * booking admin.
 */
class BookingPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Everything except force-delete — that stays super_admin-only. */
    private const array MANAGER_PERMISSIONS = [
        'ViewAny:Booking', 'View:Booking', 'Create:Booking', 'Update:Booking', 'Delete:Booking', 'Restore:Booking',
        'Confirm:Booking', 'Cancel:Booking', 'Reschedule:Booking', 'Complete:Booking', 'Manage:BookingMeeting',
        'ViewAny:BookingType', 'View:BookingType', 'Create:BookingType', 'Update:BookingType', 'Delete:BookingType', 'Restore:BookingType',
        'ViewAny:TeacherAvailability', 'View:TeacherAvailability', 'Create:TeacherAvailability', 'Update:TeacherAvailability', 'Delete:TeacherAvailability',
        'ViewAny:TeacherUnavailability', 'View:TeacherUnavailability', 'Create:TeacherUnavailability', 'Update:TeacherUnavailability', 'Delete:TeacherUnavailability',
        // Student-facing pricing matrix (Phase 10.2D) — admin only, never
        // granted to the instructor role.
        'ViewAny:StudentLessonPrice', 'View:StudentLessonPrice', 'Create:StudentLessonPrice', 'Update:StudentLessonPrice', 'Delete:StudentLessonPrice', 'Restore:StudentLessonPrice',
    ];

    private const array SUPER_ONLY_PERMISSIONS = [
        'ForceDelete:Booking',
        'ForceDelete:BookingType',
        'ForceDelete:StudentLessonPrice',
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
