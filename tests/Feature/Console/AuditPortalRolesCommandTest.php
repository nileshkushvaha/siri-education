<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditPortalRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_leftover_and_dual_role_accounts_without_changing_anything(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $leftover = User::factory()->create(['status' => 'active', 'email' => 'leftover@example.test']);
        $leftover->assignRole('instructor');
        $leftover->profile()->update(['student_status' => StudentStatus::Active, 'instructor_status' => InstructorStatus::Approved]);

        $dual = User::factory()->activeStudent()->create(['status' => 'active', 'email' => 'dual@example.test']);
        $dual->assignRole('instructor');
        $dual->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $clean = User::factory()->create(['status' => 'active', 'email' => 'clean@example.test']);
        $clean->assignRole('instructor');
        $clean->profile()->update(['instructor_status' => InstructorStatus::Draft]);

        $this->artisan('users:audit-portal-roles')
            ->expectsOutputToContain('1 instructor-only account(s) still carry a student status.')
            ->expectsOutputToContain('leftover@example.test')
            ->expectsOutputToContain('1 dual-role account(s)')
            ->expectsOutputToContain('dual@example.test')
            ->doesntExpectOutputToContain('clean@example.test')
            ->assertSuccessful();

        $this->assertSame(StudentStatus::Active, $leftover->fresh()->profile->student_status);
        $this->assertTrue($leftover->fresh()->hasRole('instructor'));
        $this->assertTrue($dual->fresh()->hasRole('student'));
    }
}
