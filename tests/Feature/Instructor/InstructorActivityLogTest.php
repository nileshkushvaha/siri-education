<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    private function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('instructor');

        return $user;
    }

    public function test_instructor_status_approved_logs_profile_approved(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $instructor = $this->makeInstructor();
        $instructor->profile->update(['instructor_status' => 'approved']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'profile_approved',
        ]);
    }

    public function test_instructor_status_rejected_logs_profile_rejected(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $instructor = $this->makeInstructor();
        $instructor->profile->update(['instructor_status' => 'rejected']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'profile_rejected',
        ]);
    }

    public function test_instructor_status_active_logs_profile_active(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $instructor = $this->makeInstructor();
        $instructor->profile->update(['instructor_status' => 'active']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'profile_active',
        ]);
    }

    public function test_profile_visibility_change_for_instructor_logs_visibility_changed(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $instructor = $this->makeInstructor();
        $instructor->profile->update(['profile_visibility' => 'private']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'visibility_changed',
        ]);
    }

    public function test_is_featured_change_logs_featured_changed(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $instructor = $this->makeInstructor();
        $instructor->profile->update(['is_featured' => true]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'featured_changed',
        ]);
    }

    public function test_non_instructor_profile_visibility_change_does_not_log_instructor_event(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $user = User::factory()->create(['status' => 'active']);
        // Does NOT have instructor role
        $user->profile->update(['profile_visibility' => 'private']);

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'instructor',
            'event' => 'visibility_changed',
        ]);
    }
}
