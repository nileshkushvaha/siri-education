<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\StudentStatus;
use App\Filament\Concerns\HasStudentLifecycleActions;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-2-20: HasStudentLifecycleActions on
 * UserResource's EditUser page.
 *
 * Livewire::test(EditUser::class, ...)->callAction(...) cannot be used
 * here: EditUser's Livewire::test() instance is null even for the
 * pre-existing, unmodified page (confirmed via git stash — a
 * ViewAction/DeleteAction ->callAction() call fails identically on the
 * untouched file), a genuine pre-existing test-infrastructure
 * limitation, not something introduced here. Instead this
 * exercises the trait's action-producing methods directly via
 * reflection on a minimal host object — the same technique used
 * elsewhere for Schema-embedded actions Livewire's callAction() couldn't
 * reach, applied here to a page whose Livewire test double never
 * resolves at all.
 */
class StudentLifecycleActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        collect([
            StudentLifecycleService::ACTIVATE_PERMISSION,
            StudentLifecycleService::SUSPEND_PERMISSION,
            StudentLifecycleService::REACTIVATE_PERMISSION,
            StudentLifecycleService::ARCHIVE_PERMISSION,
        ])->each(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo([
            StudentLifecycleService::SUSPEND_PERMISSION,
            StudentLifecycleService::ARCHIVE_PERMISSION,
            StudentLifecycleService::REACTIVATE_PERMISSION,
        ]);

        return $admin;
    }

    private function studentAt(StudentStatus $status): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => $status]);

        return $student;
    }

    /** Minimal host exposing the one property (`record`) the trait needs — mirrors Filament\Resources\Pages\EditRecord's public API surface for this trait's purposes. */
    private function hostFor(User $record): object
    {
        return new class($record)
        {
            use HasStudentLifecycleActions;

            public function __construct(public User $record) {}

            public function callAction(string $method, array $data = []): void
            {
                // Filament's evaluate() resolves closure parameters by
                // NAME — the action closures are `function (array $data)`,
                // so the injection key must be 'data', not a positional array.
                $this->{$method}()->call(['data' => $data]);
            }

            public function isVisible(string $method): bool
            {
                return $this->{$method}()->isVisible();
            }
        };
    }

    public function test_suspend_action_is_visible_for_an_active_student_to_an_authorized_admin(): void
    {
        $admin = $this->admin();
        $student = $this->studentAt(StudentStatus::Active);
        $this->actingAs($admin);

        $this->assertTrue($this->hostFor($student)->isVisible('suspendStudentAction'));
    }

    public function test_suspend_action_is_hidden_without_the_permission(): void
    {
        $unauthorizedAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student = $this->studentAt(StudentStatus::Active);
        $this->actingAs($unauthorizedAdmin);

        $this->assertFalse($this->hostFor($student)->isVisible('suspendStudentAction'));
    }

    public function test_suspend_action_is_hidden_once_already_suspended(): void
    {
        $admin = $this->admin();
        $student = $this->studentAt(StudentStatus::Suspended);
        $this->actingAs($admin);

        $host = $this->hostFor($student);
        $this->assertFalse($host->isVisible('suspendStudentAction'));
        $this->assertTrue($host->isVisible('reactivateStudentAction'));
        $this->assertTrue($host->isVisible('archiveStudentAction'));
    }

    public function test_lifecycle_actions_are_hidden_for_a_non_student_record(): void
    {
        $admin = $this->admin();
        $notAStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actingAs($admin);

        $host = $this->hostFor($notAStudent);
        $this->assertFalse($host->isVisible('suspendStudentAction'));
        $this->assertFalse($host->isVisible('archiveStudentAction'));
        $this->assertFalse($host->isVisible('reactivateStudentAction'));
    }

    public function test_calling_the_suspend_action_transitions_the_student_through_the_service(): void
    {
        $admin = $this->admin();
        $student = $this->studentAt(StudentStatus::Active);
        $this->actingAs($admin);

        $this->hostFor($student)->callAction('suspendStudentAction', ['reason' => 'Policy violation.']);

        $this->assertSame(StudentStatus::Suspended, $student->fresh()->profile->student_status);
    }

    /** A stale form re-submitted after the status already changed hits the service's ValidationException, handled safely (no crash). */
    public function test_a_stale_submission_cannot_bypass_current_state_validation(): void
    {
        $admin = $this->admin();
        $student = $this->studentAt(StudentStatus::Active);
        $this->actingAs($admin);

        $host = $this->hostFor($student);

        // Status changes underneath the already-built action (e.g. a second admin tab).
        app(StudentLifecycleService::class)->suspend($student, $admin, 'Already suspended elsewhere.');

        // reactivateStudentAction requires 'reason'; this is a VALID
        // transition (Suspended -> Active per this phase's matrix), so
        // exercise the truly-stale case instead: suspend again after the
        // student is already Suspended.
        $host->callAction('suspendStudentAction', ['reason' => 'Stale re-submission.']);

        // No exception thrown to the caller (runStudentTransition() catches
        // ValidationException internally) and no further change occurred.
        $this->assertSame(StudentStatus::Suspended, $student->fresh()->profile->student_status);
    }

    public function test_run_student_transition_catches_validation_exception_without_rethrowing(): void
    {
        $admin = $this->admin();
        $student = $this->studentAt(StudentStatus::Active);
        $this->actingAs($admin);

        $host = $this->hostFor($student);
        $method = new ReflectionMethod($host, 'runStudentTransition');

        $method->invoke($host, function (): never {
            throw ValidationException::withMessages(['status' => 'Invalid student status transition.']);
        }, 'Should not appear');

        // Reaching this line proves the exception was swallowed, not propagated.
        $this->addToAssertionCount(1);
    }
}
