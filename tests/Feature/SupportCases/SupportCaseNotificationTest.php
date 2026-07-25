<?php

declare(strict_types=1);

namespace Tests\Feature\SupportCases;

use App\Listeners\SupportCases\SendSupportCaseNotifications;
use App\Models\SupportCase;
use App\Models\User;
use App\Notifications\SupportCase\SupportCaseCreatedNotification;
use App\Notifications\SupportCase\SupportCaseReplyNotification;
use App\SupportCases\DTOs\CreateSupportCaseData;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use App\SupportCases\Enums\SupportCaseType;
use App\SupportCases\Events\SupportCaseCreated;
use App\SupportCases\Events\SupportCaseReplyAdded;
use App\SupportCases\Services\SupportCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §25.45: case notifications are queued, after-commit, and
 * idempotent — a redelivered event never double-sends
 * (NotificationIdempotencyGuard's unique DB constraint).
 */
class SupportCaseNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'AddInternalNote:SupportCase', 'guard_name' => 'web']);
    }

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        return $manager;
    }

    public function test_case_creation_dispatches_an_after_commit_event(): void
    {
        Event::fake([SupportCaseCreated::class]);

        $student = $this->student();

        app(SupportCaseService::class)->create(new CreateSupportCaseData(
            creator: $student,
            type: SupportCaseType::Student,
            category: SupportCaseCategory::Other,
            priority: SupportCasePriority::Medium,
            subject: 'Test case',
            description: 'Testing event dispatch.',
            student: $student,
        ));

        Event::assertDispatched(SupportCaseCreated::class);
    }

    public function test_requester_receives_a_created_notification_end_to_end(): void
    {
        Notification::fake();

        $student = $this->student();

        app(SupportCaseService::class)->create(new CreateSupportCaseData(
            creator: $student,
            type: SupportCaseType::Student,
            category: SupportCaseCategory::Other,
            priority: SupportCasePriority::Medium,
            subject: 'Test case',
            description: 'Testing notification delivery.',
            student: $student,
        ));

        Notification::assertSentTo($student, SupportCaseCreatedNotification::class, 1);
    }

    public function test_a_redelivered_created_event_never_double_sends(): void
    {
        Notification::fake();

        $student = $this->student();
        $case = SupportCase::factory()->forStudent($student)->create();
        $listener = app(SendSupportCaseNotifications::class);

        $listener->handleCreated(new SupportCaseCreated($case));
        $listener->handleCreated(new SupportCaseCreated($case)); // simulated redelivery

        Notification::assertSentToTimes($student, SupportCaseCreatedNotification::class, 1);
    }

    public function test_internal_note_replies_never_trigger_a_participant_notification(): void
    {
        Notification::fake();

        $student = $this->student();
        $case = SupportCase::factory()->forStudent($student)->create();
        $staff = $this->manager();
        $staff->givePermissionTo('AddInternalNote:SupportCase');

        $reply = app(SupportCaseService::class)->addReply($case, $staff, 'Internal only.', SupportCaseReplyVisibility::InternalNote);

        app(SendSupportCaseNotifications::class)->handleReplyAdded(new SupportCaseReplyAdded($reply));

        Notification::assertNothingSent();
    }

    public function test_requester_reply_notifies_the_assigned_staff_member(): void
    {
        Notification::fake();

        $student = $this->student();
        $manager = $this->manager();
        $case = SupportCase::factory()->forStudent($student)->create(['assigned_to' => $manager->id]);

        $reply = app(SupportCaseService::class)->addReply($case, $student, 'Any update?', SupportCaseReplyVisibility::RequesterVisible);

        app(SendSupportCaseNotifications::class)->handleReplyAdded(new SupportCaseReplyAdded($reply));

        Notification::assertSentTo($manager, SupportCaseReplyNotification::class);
        Notification::assertNotSentTo($student, SupportCaseReplyNotification::class);
    }
}
