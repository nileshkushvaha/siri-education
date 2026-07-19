<?php

declare(strict_types=1);

namespace Tests\Feature\Student\Concurrency;

use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Spatie\Permission\Models\Permission;

/**
 * TRUE cross-process race (Phase 24H): two independent worker processes
 * call StudentLifecycleService::suspend() for the SAME Active student at
 * the same instant, over separate MySQL connections — proving the
 * lockForUpdate() guard in transitionStatus() converges to exactly one
 * Active -> Suspended transition (and one audit record) under genuine
 * concurrency, mirroring InstructorLifecycleRaceTest's exact approach.
 */
class StudentLifecycleRaceTest extends ConcurrencyTestCase
{
    public function test_two_concurrent_suspensions_converge_to_exactly_one_success(): void
    {
        [$adminOne, $adminTwo, $student] = $this->fixtures();

        $results = $this->race([
            ['student-suspend', ['admin_id' => $adminOne->id, 'student_id' => $student->id]],
            ['student-suspend', ['admin_id' => $adminTwo->id, 'student_id' => $student->id]],
        ]);

        $succeeded = array_values(array_filter($results, static fn (array $r): bool => $r['ok'] === true));
        $failed = array_values(array_filter($results, static fn (array $r): bool => $r['ok'] === false));

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent suspensions must succeed: '.json_encode($results));
        $this->assertCount(1, $failed, 'Exactly one of the two concurrent suspensions must be rejected: '.json_encode($results));

        $this->assertSame('suspended', $succeeded[0]['result']['student_status']);
        $this->assertStringContainsString('Invalid student status transition', $failed[0]['message']);

        $this->assertSame(StudentStatus::Suspended, $student->fresh()->profile->student_status);

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'student')->where('event', 'student_status_changed')->count(),
            'Concurrent transitions against the same student must converge to exactly one audit entry.',
        );
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function fixtures(): array
    {
        Permission::firstOrCreate(['name' => StudentLifecycleService::SUSPEND_PERMISSION, 'guard_name' => 'web']);

        $adminOne = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $adminOne->assignRole('manager');
        $adminOne->givePermissionTo(StudentLifecycleService::SUSPEND_PERMISSION);

        $adminTwo = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $adminTwo->assignRole('manager');
        $adminTwo->givePermissionTo(StudentLifecycleService::SUSPEND_PERMISSION);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        return [$adminOne, $adminTwo, $student];
    }
}
