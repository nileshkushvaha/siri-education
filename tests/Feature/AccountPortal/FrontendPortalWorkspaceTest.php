<?php

declare(strict_types=1);

namespace Tests\Feature\AccountPortal;

use App\Enums\InstructorStatus;
use App\Enums\PortalAudience;
use App\Models\User;
use App\Services\FrontendPortalAudienceResolver;
use App\Services\FrontendPortalWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FrontendPortalWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private FrontendPortalAudienceResolver $resolver;

    private FrontendPortalWorkspaceService $workspaces;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->resolver = app(FrontendPortalAudienceResolver::class);
        $this->workspaces = app(FrontendPortalWorkspaceService::class);
    }

    public function test_student_only_resolves_to_student(): void
    {
        $user = $this->user('student');

        $this->assertSame(PortalAudience::Student, $this->resolver->resolve($user));
        $this->assertSame([['key' => 'student', 'label' => 'Student']], $this->workspaces->availableWorkspaces($user));
    }

    public function test_instructor_only_resolves_to_instructor(): void
    {
        $user = $this->approvedInstructor();

        $this->assertSame(PortalAudience::Instructor, $this->resolver->resolve($user));
        $this->assertSame([['key' => 'instructor', 'label' => 'Instructor']], $this->workspaces->availableWorkspaces($user));
    }

    public function test_dual_role_with_approved_instructor_status_and_selection_switches_workspace(): void
    {
        $user = $this->user('student');
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Approved]);
        $user->refresh();

        $this->assertTrue($this->workspaces->selectWorkspace($user, PortalAudience::Instructor));
        $this->assertSame(PortalAudience::Instructor, $this->resolver->resolve($user));

        $this->assertTrue($this->workspaces->selectWorkspace($user, PortalAudience::Student));
        $this->assertSame(PortalAudience::Student, $this->resolver->resolve($user));
    }

    public function test_dual_role_without_a_selection_falls_back_to_student(): void
    {
        $user = $this->user('student');
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Approved]);
        $user->refresh();

        $this->assertSame(PortalAudience::Student, $this->resolver->resolve($user));
    }

    public function test_invalid_session_selection_is_ignored(): void
    {
        $user = $this->user('student');
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Approved]);
        $user->refresh();

        session([FrontendPortalWorkspaceService::SESSION_KEY => 'not-a-real-audience']);

        $this->assertSame(PortalAudience::Student, $this->resolver->resolve($user));
    }

    public function test_student_cannot_force_instructor_session_without_holding_an_eligible_instructor_status(): void
    {
        // Holds the instructor role (e.g. draft applicant) but is not yet
        // Approved/Active/Vacation — the workspace must not honor a
        // tampered/stale session value claiming otherwise.
        $user = $this->user('student');
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Draft]);
        $user->refresh();

        session([FrontendPortalWorkspaceService::SESSION_KEY => 'instructor']);

        $this->assertFalse($this->workspaces->canAccessInstructorWorkspace($user));
        $this->assertSame(PortalAudience::Student, $this->resolver->resolve($user));
    }

    public function test_select_workspace_rejects_a_workspace_the_user_cannot_access(): void
    {
        $user = $this->user('student');

        $this->assertFalse($this->workspaces->selectWorkspace($user, PortalAudience::Instructor));
        $this->assertSame(PortalAudience::Student, $this->resolver->resolve($user));
    }

    public function test_vacation_status_instructor_may_still_use_the_instructor_workspace(): void
    {
        $user = $this->user('student');
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Vacation]);
        $user->refresh();

        $this->assertTrue($this->workspaces->canAccessInstructorWorkspace($user));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function approvedInstructor(): User
    {
        $user = $this->user('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Approved]);

        return $user->refresh();
    }
}
