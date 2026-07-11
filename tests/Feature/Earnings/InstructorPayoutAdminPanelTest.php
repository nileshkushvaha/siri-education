<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use Database\Seeders\InstructorPayoutPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorPayoutAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_payout_resources_render_for_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        InstructorPayoutMethod::factory()->pendingVerification()->create();
        InstructorWithdrawalRequest::factory()->create();

        $this->actingAs($admin)->get('/admin/instructor-payout-methods')->assertOk();
        $this->actingAs($admin)->get('/admin/instructor-withdrawal-requests')->assertOk();
    }

    public function test_payout_resources_render_for_seeded_manager(): void
    {
        $this->seed(InstructorPayoutPermissionSeeder::class);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        InstructorPayoutMethod::factory()->pendingVerification()->create();

        $this->actingAs($manager)->get('/admin/instructor-payout-methods')->assertOk();
        $this->actingAs($manager)->get('/admin/instructor-withdrawal-requests')->assertOk();
    }

    public function test_payout_resources_deny_users_without_permissions(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get('/admin/instructor-payout-methods')->assertForbidden();
        $this->actingAs($user)->get('/admin/instructor-withdrawal-requests')->assertForbidden();
    }

    public function test_payout_method_table_never_renders_sensitive_values(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $method = InstructorPayoutMethod::factory()->pendingVerification()->create();
        $accountNumber = $method->encrypted_details['account_number'];

        $this->actingAs($admin)
            ->get('/admin/instructor-payout-methods')
            ->assertOk()
            ->assertDontSee($accountNumber)
            ->assertDontSee('TEST0001234')
            ->assertSee($method->masked_identifier);
    }

    public function test_withdrawal_table_never_renders_the_snapshot(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $request = InstructorWithdrawalRequest::factory()->create();
        $accountNumber = $request->encrypted_payout_method_snapshot['account_number'];

        $this->actingAs($admin)
            ->get('/admin/instructor-withdrawal-requests')
            ->assertOk()
            ->assertDontSee($accountNumber)
            ->assertSee($request->reference);
    }
}
