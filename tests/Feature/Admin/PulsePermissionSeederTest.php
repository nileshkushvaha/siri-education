<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\PulsePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 24O — GAP-033: `pulse.view` mirrors the QueueMonitor/SchedulerMonitor permission convention. */
class PulsePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_receives_view_permission_after_seeding(): void
    {
        $this->seed(PulsePermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->assertTrue($manager->can('pulse.view'));
    }

    public function test_plain_user_is_denied_even_after_seeding(): void
    {
        $this->seed(PulsePermissionSeeder::class);

        $user = User::factory()->create();

        $this->assertFalse($user->can('pulse.view'));
    }

    public function test_super_admin_bypasses_without_any_seeded_permission(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->assertTrue($admin->can('pulse.view'));
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(PulsePermissionSeeder::class);
        $this->seed(PulsePermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->assertTrue($manager->can('pulse.view'));
    }
}
