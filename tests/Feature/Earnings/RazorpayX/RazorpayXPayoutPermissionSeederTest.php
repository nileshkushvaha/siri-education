<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use App\Models\User;
use Database\Seeders\RazorpayXPayoutPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RazorpayXPayoutPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const array MANAGER_PERMISSIONS = [
        'Configure:RazorpayXPayout',
        'TestConnection:RazorpayXPayout',
        'ConfirmIpAllowlisting:RazorpayXPayout',
        'ProvisionDestination:RazorpayXPayout',
        'RefreshDestination:RazorpayXPayout',
        'ViewProviderDetails:RazorpayXPayout',
        'ProcessWebhook:RazorpayXPayout',
        'Reconcile:RazorpayXPayout',
    ];

    public function test_seeder_creates_all_permissions_and_grants_manager(): void
    {
        $this->seed(RazorpayXPayoutPermissionSeeder::class);

        foreach (self::MANAGER_PERMISSIONS as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists(),
                "Missing permission: {$permission}",
            );
        }

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        foreach (self::MANAGER_PERMISSIONS as $permission) {
            $this->assertTrue($manager->hasPermissionTo($permission), "Manager missing: {$permission}");
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RazorpayXPayoutPermissionSeeder::class);
        $this->seed(RazorpayXPayoutPermissionSeeder::class);

        $this->assertSame(
            count(self::MANAGER_PERMISSIONS),
            Permission::query()->where('name', 'like', '%:RazorpayXPayout')->count(),
        );
    }

    /** No Manage/MarkPaid/Delete/Edit permission — execution stays gated by the existing Execute:InstructorPayoutAttempt permission. */
    public function test_no_manage_or_mark_paid_or_execution_permission_is_seeded(): void
    {
        $this->seed(RazorpayXPayoutPermissionSeeder::class);

        foreach (['Manage:RazorpayXPayout', 'MarkPaid:RazorpayXPayout', 'Delete:RazorpayXPayout', 'Edit:RazorpayXPayout', 'Execute:RazorpayXPayout'] as $forbidden) {
            $this->assertFalse(Permission::query()->where('name', $forbidden)->exists(), "Forbidden permission seeded: {$forbidden}");
        }
    }
}
