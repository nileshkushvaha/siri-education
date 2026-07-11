<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Models\User;
use Database\Seeders\InstructorPayoutPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InstructorPayoutPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:InstructorPayoutMethod', 'View:InstructorPayoutMethod',
        'ViewSensitive:InstructorPayoutMethod', 'Verify:InstructorPayoutMethod',
        'Reject:InstructorPayoutMethod', 'Disable:InstructorPayoutMethod',
        'ViewAny:InstructorWithdrawalRequest', 'View:InstructorWithdrawalRequest',
        'StartReview:InstructorWithdrawalRequest', 'Approve:InstructorWithdrawalRequest',
        'Reject:InstructorWithdrawalRequest', 'Cancel:InstructorWithdrawalRequest',
    ];

    public function test_seeder_creates_all_permissions_and_grants_manager(): void
    {
        $this->seed(InstructorPayoutPermissionSeeder::class);

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
        $this->seed(InstructorPayoutPermissionSeeder::class);
        $this->seed(InstructorPayoutPermissionSeeder::class);

        $this->assertSame(
            count(self::MANAGER_PERMISSIONS),
            Permission::query()->where('name', 'like', '%InstructorPayoutMethod')->count()
                + Permission::query()->where('name', 'like', '%InstructorWithdrawalRequest')->count(),
        );
    }

    public function test_no_payout_execution_permission_is_seeded(): void
    {
        $this->seed(InstructorPayoutPermissionSeeder::class);

        $this->assertFalse(Permission::query()->where('name', 'like', 'MarkPaid:InstructorWithdrawal%')->exists());
        $this->assertFalse(Permission::query()->where('name', 'like', '%Execute%')->exists());
    }
}
