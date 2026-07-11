<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\User;
use Database\Seeders\InstructorEarningPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorEarningPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_receives_earning_permissions_after_seeding(): void
    {
        $this->seed(InstructorEarningPermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $earning = InstructorEarning::factory()->create();
        $batch = InstructorSettlementBatch::factory()->create();

        $this->assertTrue($manager->can('viewAny', InstructorEarning::class));
        $this->assertTrue($manager->can('view', $earning));
        $this->assertTrue($manager->can('release', $earning));
        $this->assertTrue($manager->can('reverse', $earning));
        $this->assertTrue($manager->can('viewAny', InstructorSettlementBatch::class));
        $this->assertTrue($manager->can('create', InstructorSettlementBatch::class));
        $this->assertTrue($manager->can('approve', $batch));
        $this->assertTrue($manager->can('markPaid', $batch));
        $this->assertTrue($manager->can('cancel', $batch));

        // Earnings are engine-created; deletion stays super_admin-only.
        $this->assertFalse($manager->can('create', InstructorEarning::class));
        $this->assertFalse($manager->can('delete', $earning));
        $this->assertFalse($manager->can('delete', $batch));
    }

    public function test_instructor_sees_only_own_earnings_and_cannot_manage_anything(): void
    {
        $instructor = User::factory()->create();
        $other = User::factory()->create();

        $own = InstructorEarning::factory()->create(['instructor_id' => $instructor->id]);
        $foreign = InstructorEarning::factory()->create(['instructor_id' => $other->id]);
        $ownBatch = InstructorSettlementBatch::factory()->create(['instructor_id' => $instructor->id]);
        $foreignBatch = InstructorSettlementBatch::factory()->create(['instructor_id' => $other->id]);

        $this->assertTrue($instructor->can('view', $own));
        $this->assertTrue($instructor->can('view', $ownBatch));

        $this->assertFalse($instructor->can('view', $foreign));
        $this->assertFalse($instructor->can('view', $foreignBatch));
        $this->assertFalse($instructor->can('viewAny', InstructorEarning::class));
        $this->assertFalse($instructor->can('release', $own));
        $this->assertFalse($instructor->can('reverse', $own));
        $this->assertFalse($instructor->can('approve', $ownBatch));
        $this->assertFalse($instructor->can('markPaid', $ownBatch));
        $this->assertFalse($instructor->can('cancel', $ownBatch));
    }

    public function test_seeding_is_idempotent_and_plain_users_stay_denied(): void
    {
        $this->seed(InstructorEarningPermissionSeeder::class);
        $this->seed(InstructorEarningPermissionSeeder::class);

        $user = User::factory()->create();

        $this->assertFalse($user->can('viewAny', InstructorEarning::class));
        $this->assertFalse($user->can('viewAny', InstructorSettlementBatch::class));
    }
}
