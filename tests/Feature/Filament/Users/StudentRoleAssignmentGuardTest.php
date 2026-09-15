<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Users;

use App\Enums\StudentStatus;
use App\Exceptions\StudentRoleNotAssignableException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Services\Auth\StudentRoleAssignmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Admins cannot turn an existing instructor into a student; new accounts and existing students are unaffected. */
final class StudentRoleAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    private Role $student;

    private Role $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->student = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->instructor = Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    public function test_the_guard_refuses_student_for_an_account_that_never_held_it(): void
    {
        $instructorOnly = User::factory()->create(['status' => 'active']);
        $instructorOnly->assignRole('instructor');
        $guard = app(StudentRoleAssignmentGuard::class);

        $guard->assertSubmittedRolesAllowed($instructorOnly, null);
        $guard->assertSubmittedRolesAllowed($instructorOnly, [$this->instructor->id]);

        $this->expectException(StudentRoleNotAssignableException::class);
        $guard->assertSubmittedRolesAllowed($instructorOnly, [$this->instructor->id, $this->student->id]);
    }

    public function test_adding_student_to_an_instructor_only_account_is_halted_in_the_admin_form(): void
    {
        $instructorOnly = User::factory()->create(['status' => 'active']);
        $instructorOnly->assignRole('instructor');

        Livewire::test(EditUser::class, ['record' => $instructorOnly->getRouteKey()])
            ->assertSee('granted at registration only')
            ->fillForm(['roles' => [$this->instructor->id, $this->student->id]])
            ->call('save');

        $fresh = $instructorOnly->fresh();
        $this->assertFalse($fresh->hasRole('student'));
        $this->assertTrue($fresh->hasRole('instructor'));
        $this->assertNull($fresh->profile->student_status);
    }

    public function test_an_existing_student_keeps_the_role_and_may_also_become_an_instructor(): void
    {
        $student = User::factory()->activeStudent()->create(['status' => 'active']);

        Livewire::test(EditUser::class, ['record' => $student->getRouteKey()])
            ->fillForm(['roles' => [$this->student->id, $this->instructor->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $student->fresh();
        $this->assertTrue($fresh->hasRole('student'));
        $this->assertTrue($fresh->hasRole('instructor'));
    }

    public function test_a_new_account_may_still_be_created_as_a_student(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Student',
                'email' => 'new-student@example.test',
                'password' => 'StrongPass123!',
                'password_confirmation' => 'StrongPass123!',
                'status' => 'active',
                'roles' => [$this->student->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new-student@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('student'));
        $this->assertSame(StudentStatus::Registered, $user->profile->student_status);
    }
}
