<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\Pages\EditInstructorOnboarding;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorForceApproveOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');

        $this->instructor = User::factory()->create(['status' => 'active']);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile->update(['instructor_status' => InstructorStatus::UnderReview]);
    }

    public function test_force_approve_requires_a_reason(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(EditInstructorOnboarding::class, ['record' => $this->instructor->getRouteKey()])
            ->callAction('forceApproveInstructor', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(InstructorStatus::UnderReview, $this->instructor->fresh()->profile->instructor_status);
    }

    public function test_force_approve_with_a_reason_sets_status_to_approved(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(EditInstructorOnboarding::class, ['record' => $this->instructor->getRouteKey()])
            ->callAction('forceApproveInstructor', data: ['reason' => 'Verified credentials over phone call'])
            ->assertHasNoActionErrors();

        $this->assertSame(InstructorStatus::Approved, $this->instructor->fresh()->profile->instructor_status);
    }

    public function test_force_approve_logs_an_admin_override_with_the_reason(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(EditInstructorOnboarding::class, ['record' => $this->instructor->getRouteKey()])
            ->callAction('forceApproveInstructor', data: ['reason' => 'Verified credentials over phone call']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'admin_override',
            'causer_id' => $this->superAdmin->id,
            'subject_type' => User::class,
            'subject_id' => $this->instructor->id,
        ]);

        $activity = Activity::where('event', 'admin_override')->firstOrFail();
        $this->assertSame('Verified credentials over phone call', $activity->properties->get('override_reason'));
        $this->assertTrue($activity->properties->get('is_override'));
    }

    public function test_force_approve_action_is_not_visible_for_already_bookable_instructor(): void
    {
        $this->instructor->profile->update(['instructor_status' => InstructorStatus::Approved]);
        $this->actingAs($this->superAdmin);

        Livewire::test(EditInstructorOnboarding::class, ['record' => $this->instructor->getRouteKey()])
            ->assertActionHidden('forceApproveInstructor');
    }

    public function test_non_instructor_is_not_reachable_through_this_resource_at_all(): void
    {
        // Stronger than a hidden action: InstructorOnboardingResource's
        // query is scoped to the instructor role, so a non-instructor's
        // record simply doesn't exist through this resource.
        $student = User::factory()->create(['status' => 'active']);
        $this->actingAs($this->superAdmin);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditInstructorOnboarding::class, ['record' => $student->getRouteKey()]);
    }
}
