<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Database\Seeders\StudentLifecyclePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mirrors InstructorPermissionSeederTest's exact
 * pattern for the student lifecycle permissions.
 */
class StudentLifecyclePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const array PERMISSIONS = [
        StudentLifecycleService::ACTIVATE_PERMISSION,
        StudentLifecycleService::SUSPEND_PERMISSION,
        StudentLifecycleService::REACTIVATE_PERMISSION,
        StudentLifecycleService::ARCHIVE_PERMISSION,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    public function test_seeder_creates_all_permissions_and_grants_manager(): void
    {
        $this->seed(StudentLifecyclePermissionSeeder::class);

        foreach (self::PERMISSIONS as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists(),
                "Missing permission: {$permission}",
            );
        }

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        foreach (self::PERMISSIONS as $permission) {
            $this->assertTrue($manager->hasPermissionTo($permission), "Manager missing: {$permission}");
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(StudentLifecyclePermissionSeeder::class);
        $this->seed(StudentLifecyclePermissionSeeder::class);

        $this->assertSame(
            count(self::PERMISSIONS),
            Permission::query()->whereIn('name', self::PERMISSIONS)->count(),
        );
    }

    public function test_a_plain_manager_without_the_seeder_run_has_no_lifecycle_permission(): void
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        $this->assertFalse(app(StudentLifecycleService::class)->canSuspend($manager));
        $this->assertFalse(app(StudentLifecycleService::class)->canArchive($manager));
        $this->assertFalse(app(StudentLifecycleService::class)->canReactivate($manager));
        $this->assertFalse(app(StudentLifecycleService::class)->canActivate($manager));
    }
}
