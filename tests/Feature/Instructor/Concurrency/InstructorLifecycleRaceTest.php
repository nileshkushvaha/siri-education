<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor\Concurrency;

use App\Enums\InstructorStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Spatie\Permission\Models\Permission;

/**
 * TRUE cross-process race (Phase 23D): two independent worker processes
 * call InstructorOnboardingService::activate() for the same instructor
 * at the same instant, over separate MySQL connections — proving the
 * lockForUpdate() guard in transitionStatus() converges to exactly one
 * Approved -> Active transition under genuine concurrency, not just an
 * in-process simulation.
 */
class InstructorLifecycleRaceTest extends ConcurrencyTestCase
{
    public function test_two_concurrent_activations_converge_to_exactly_one_success(): void
    {
        [$adminOne, $adminTwo, $instructor] = $this->fixtures();

        $results = $this->race([
            ['instructor-activate', ['admin_id' => $adminOne->id, 'instructor_id' => $instructor->id]],
            ['instructor-activate', ['admin_id' => $adminTwo->id, 'instructor_id' => $instructor->id]],
        ]);

        $succeeded = array_values(array_filter($results, static fn (array $r): bool => $r['ok'] === true));
        $failed = array_values(array_filter($results, static fn (array $r): bool => $r['ok'] === false));

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent activations must succeed: '.json_encode($results));
        $this->assertCount(1, $failed, 'Exactly one of the two concurrent activations must be rejected: '.json_encode($results));

        $this->assertSame('active', $succeeded[0]['result']['instructor_status']);
        $this->assertStringContainsString('Invalid instructor status transition', $failed[0]['message']);

        $this->assertSame(InstructorStatus::Active, $instructor->fresh()->profile->instructor_status);

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'instructor')->where('event', 'instructor_activated')->count(),
            'Concurrent activations against the same instructor must converge to exactly one audit entry.',
        );
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function fixtures(): array
    {
        Permission::firstOrCreate(['name' => InstructorOnboardingService::ACTIVATE_PERMISSION, 'guard_name' => 'web']);

        $adminOne = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $adminOne->assignRole('manager');
        $adminOne->givePermissionTo(InstructorOnboardingService::ACTIVATE_PERMISSION);

        $adminTwo = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $adminTwo->assignRole('manager');
        $adminTwo->givePermissionTo(InstructorOnboardingService::ACTIVATE_PERMISSION);

        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Approved]);

        return [$adminOne, $adminTwo, $instructor];
    }
}
