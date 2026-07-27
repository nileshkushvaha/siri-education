<?php

declare(strict_types=1);

namespace Tests\Feature\Student\Concurrency;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\StudentFavoriteInstructor;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Spatie\Permission\Models\Permission;

/**
 * TRUE cross-process race: two independent worker processes
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

    /**
     * A delayed verification/legacy-
     * reconciliation activation racing an admin suspension for the SAME
     * Registered student. Whichever operation's transaction commits
     * first, the row lock means the loser re-reads the just-committed
     * status — either ordering (align-then-suspend, or suspend-sees-
     * already-not-Registered-and-no-ops) must converge to Suspended,
     * proving reconciliation can never resurrect a concurrently-
     * suspended account back to Active.
     */
    public function test_concurrent_legacy_alignment_and_suspension_converge_to_suspended(): void
    {
        Permission::firstOrCreate(['name' => StudentLifecycleService::SUSPEND_PERMISSION, 'guard_name' => 'web']);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo(StudentLifecycleService::SUSPEND_PERMISSION);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Registered]);

        $results = $this->race([
            ['student-align-legacy', ['student_id' => $student->id]],
            ['student-suspend', ['admin_id' => $admin->id, 'student_id' => $student->id]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], 'Both operations must complete without an unexpected error: '.json_encode($result));
        }

        $this->assertSame(
            StudentStatus::Suspended,
            $student->fresh()->profile->student_status,
            'Regardless of ordering, a concurrently-requested suspension must win — reconciliation must never leave the account Active.',
        );
    }

    /**
     * An interactive student action (favorite) racing an
     * admin suspension of the SAME student. The favorite's
     * in-transaction locked status read serializes against suspend's
     * profile-row lock, so exactly two outcomes are valid: favorite
     * committed BEFORE the suspension became authoritative (row exists),
     * or favorite observed the committed suspension and was rejected (no
     * row, generic message). A favorite committing on stale
     * pre-suspension state — rejected AND row present, or ok AND
     * rejected-side effects — must never occur. Final status is
     * Suspended in every ordering.
     */
    public function test_favorite_racing_suspension_produces_a_valid_serialized_outcome(): void
    {
        Permission::firstOrCreate(['name' => StudentLifecycleService::SUSPEND_PERMISSION, 'guard_name' => 'web']);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo(StudentLifecycleService::SUSPEND_PERMISSION);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update([
            'instructor_status' => InstructorStatus::Active,
            'profile_visibility' => 'public',
        ]);

        $results = $this->race([
            ['student-favorite', ['student_id' => $student->id, 'instructor_id' => $instructor->id]],
            ['student-suspend', ['admin_id' => $admin->id, 'student_id' => $student->id]],
        ]);

        $favoriteResult = collect($results)->firstWhere('op', 'student-favorite');
        $suspendResult = collect($results)->firstWhere('op', 'student-suspend');

        $this->assertTrue($suspendResult['ok'], 'The suspension must always succeed: '.json_encode($results));
        $this->assertSame(StudentStatus::Suspended, $student->fresh()->profile->student_status);

        $rowExists = StudentFavoriteInstructor::query()
            ->where('student_user_id', $student->id)
            ->where('instructor_user_id', $instructor->id)
            ->exists();

        if ($favoriteResult['ok']) {
            $this->assertTrue($rowExists, 'A successful favorite must have committed its row: '.json_encode($results));
        } else {
            $this->assertFalse($rowExists, 'A rejected favorite must leave no partial record: '.json_encode($results));
            $this->assertStringContainsString('not available for this action', $favoriteResult['message'], 'Rejection must use the generic message: '.json_encode($results));
        }
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
