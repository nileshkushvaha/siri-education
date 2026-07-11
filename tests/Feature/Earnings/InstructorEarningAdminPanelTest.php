<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\User;
use Database\Seeders\InstructorEarningPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorEarningAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_earning_resources_render_for_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        InstructorEarning::factory()->count(2)->create();
        InstructorSettlementBatch::factory()->create();

        $this->actingAs($admin)->get('/admin/instructor-earnings')->assertOk();
        $this->actingAs($admin)->get('/admin/instructor-settlement-batches')->assertOk();
        $this->actingAs($admin)->get('/admin/settings/instructor-earnings')->assertOk();
    }

    public function test_earning_resources_render_for_seeded_manager(): void
    {
        $this->seed(InstructorEarningPermissionSeeder::class);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        InstructorEarning::factory()->create();

        $this->actingAs($manager)->get('/admin/instructor-earnings')->assertOk();
        $this->actingAs($manager)->get('/admin/instructor-settlement-batches')->assertOk();
    }

    public function test_earning_resources_deny_users_without_permissions(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get('/admin/instructor-earnings')->assertForbidden();
        $this->actingAs($user)->get('/admin/instructor-settlement-batches')->assertForbidden();
        $this->actingAs($user)->get('/admin/settings/instructor-earnings')->assertForbidden();
    }
}
