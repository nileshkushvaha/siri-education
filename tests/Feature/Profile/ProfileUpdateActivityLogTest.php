<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UserProfileObserver's generic 'profile_updated' event fires for ANY
 * user (student or instructor) when tracked profile-content fields
 * change — not just for instructor-role accounts, which additionally
 * get the 'instructor' log entries tested in InstructorActivityLogTest.
 */
class ProfileUpdateActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_profile_update_is_logged(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        $student->profile->update(['headline' => 'Aspiring engineer']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'profile',
            'event' => 'profile_updated',
        ]);
    }

    public function test_untracked_field_change_does_not_log_profile_updated(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        // profile_completion is system-recalculated, not user-authored content.
        $student->profile->update(['profile_completion' => 42]);

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'profile',
            'event' => 'profile_updated',
        ]);
    }

    public function test_profile_updated_lists_the_changed_fields(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        $student->profile->update(['bio' => 'Loves math', 'city' => 'Pune']);

        $activity = Activity::where('event', 'profile_updated')->firstOrFail();
        $this->assertEqualsCanonicalizing(['bio', 'city'], $activity->properties->get('changed_fields'));
    }

    public function test_non_instructor_profile_update_does_not_log_instructor_events(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        $student->profile->update(['profile_visibility' => 'private']);

        $this->assertDatabaseMissing('activity_log', ['log_name' => 'instructor']);
    }

    public function test_instructor_profile_update_logs_both_generic_and_instructor_events(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');
        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        $instructor->profile->update(['headline' => 'PhD in Physics', 'is_featured' => true]);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'profile', 'event' => 'profile_updated']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'instructor', 'event' => 'featured_changed']);
    }
}
