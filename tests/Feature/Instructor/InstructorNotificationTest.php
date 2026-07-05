<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Events\ActivityCreated;
use App\Listeners\NotifyInstructorOnProfileActivity;
use App\Models\User;
use App\Notifications\Instructor\InstructorProfileStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    public function test_profile_approved_event_triggers_instructor_notification(): void
    {
        Notification::fake();

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        $instructor->profile->update(['instructor_status' => 'approved']);

        Notification::assertSentTo(
            $instructor,
            InstructorProfileStatusNotification::class,
        );
    }

    public function test_profile_rejected_event_triggers_instructor_notification(): void
    {
        Notification::fake();

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        $instructor->profile->update(['instructor_status' => 'rejected']);

        Notification::assertSentTo(
            $instructor,
            InstructorProfileStatusNotification::class,
        );
    }

    public function test_profile_active_event_triggers_instructor_notification(): void
    {
        Notification::fake();

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $admin = User::factory()->create(['status' => 'active']);
        $this->actingAs($admin);

        $instructor->profile->update(['instructor_status' => 'active']);

        Notification::assertSentTo(
            $instructor,
            InstructorProfileStatusNotification::class,
        );
    }

    public function test_featured_change_does_not_trigger_status_notification(): void
    {
        Notification::fake();

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $instructor->profile->update(['is_featured' => true]);

        Notification::assertNothingSent();
    }

    public function test_notification_listener_ignores_non_instructor_log(): void
    {
        Notification::fake();

        $activity = activity('users')
            ->event('created')
            ->log('User created');

        $listener = app(NotifyInstructorOnProfileActivity::class);
        $listener->handle(new ActivityCreated($activity));

        Notification::assertNothingSent();
    }
}
