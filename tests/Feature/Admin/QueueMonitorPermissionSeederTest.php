<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\QueueMonitorPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 24N — GAP-034: `queue_monitor.retry_failed_jobs` is a distinct permission from `queue_monitor.view`. */
class QueueMonitorPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_receives_view_and_retry_permissions_after_seeding(): void
    {
        $this->seed(QueueMonitorPermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->assertTrue($manager->can('queue_monitor.view'));
        $this->assertTrue($manager->can('queue_monitor.retry_failed_jobs'));
    }

    public function test_plain_user_is_denied_both_permissions_even_after_seeding(): void
    {
        $this->seed(QueueMonitorPermissionSeeder::class);

        $user = User::factory()->create();

        $this->assertFalse($user->can('queue_monitor.view'));
        $this->assertFalse($user->can('queue_monitor.retry_failed_jobs'));
    }

    public function test_super_admin_bypasses_without_any_seeded_permission(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->assertTrue($admin->can('queue_monitor.view'));
        $this->assertTrue($admin->can('queue_monitor.retry_failed_jobs'));
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(QueueMonitorPermissionSeeder::class);
        $this->seed(QueueMonitorPermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->assertTrue($manager->can('queue_monitor.retry_failed_jobs'));
    }
}
