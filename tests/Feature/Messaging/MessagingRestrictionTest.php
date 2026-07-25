<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Messaging\Enums\ConversationStatus;
use App\Messaging\Exceptions\MessagingException;
use App\Messaging\Services\MessagingService;
use App\Models\MessagingRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/** SRS §17.36 "Restrict messaging"/"Suspend messaging access" — always with a mandatory reason. */
class MessagingRestrictionTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
        Permission::firstOrCreate(['name' => 'Restrict:Messaging', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo('Restrict:Messaging');

        return $admin;
    }

    public function test_applying_a_restriction_requires_a_reason(): void
    {
        $student = $this->student();
        $admin = $this->admin();

        $this->expectException(MessagingException::class);
        app(MessagingService::class)->applyRestriction($student, $admin, '');
    }

    public function test_unauthorized_user_cannot_apply_a_restriction(): void
    {
        $student = $this->student();
        $otherStudent = $this->student();

        $this->expectException(MessagingException::class);
        app(MessagingService::class)->applyRestriction($student, $otherStudent, 'Because I said so.');
    }

    public function test_restricting_a_user_flips_their_active_conversations_to_restricted(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);

        $service->applyRestriction($instructor, $this->admin(), 'Repeated policy violations.');

        $this->assertSame(ConversationStatus::Restricted, $conversation->fresh()->status);
    }

    public function test_restricted_participant_cannot_send_messages(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $service->applyRestriction($instructor, $this->admin(), 'Policy violation.');

        $this->expectException(MessagingException::class);
        $service->send($conversation, $student, 'Are you there?');
    }

    public function test_restricted_user_cannot_open_a_new_conversation(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        app(MessagingService::class)->applyRestriction($instructor, $this->admin(), 'Policy violation.');

        $this->expectException(MessagingException::class);
        app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);
    }

    public function test_applying_a_restriction_twice_is_idempotent(): void
    {
        $instructor = $this->instructor();
        $admin = $this->admin();
        $service = app(MessagingService::class);

        $first = $service->applyRestriction($instructor, $admin, 'First reason.');
        $second = $service->applyRestriction($instructor, $admin, 'Second reason (ignored).');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MessagingRestriction::query()->count());
    }

    public function test_removing_a_restriction_requires_a_reason(): void
    {
        $instructor = $this->instructor();
        $admin = $this->admin();
        $restriction = app(MessagingService::class)->applyRestriction($instructor, $admin, 'Policy violation.');

        $this->expectException(MessagingException::class);
        app(MessagingService::class)->removeRestriction($restriction, $admin, '');
    }

    public function test_removing_a_restriction_reactivates_the_conversation(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $admin = $this->admin();
        $restriction = $service->applyRestriction($instructor, $admin, 'Policy violation.');

        $service->removeRestriction($restriction, $admin, 'Reviewed and cleared.');

        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
        $this->assertNotNull($restriction->fresh()->lifted_at);
    }

    public function test_removing_one_restriction_does_not_reactivate_a_conversation_if_the_other_participant_is_still_restricted(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $admin = $this->admin();

        $service->applyRestriction($student, $admin, 'Student violation.');
        $instructorRestriction = $service->applyRestriction($instructor, $admin, 'Instructor violation.');

        $service->removeRestriction($instructorRestriction, $admin, 'Instructor cleared.');

        $this->assertSame(ConversationStatus::Restricted, $conversation->fresh()->status, 'Student is still restricted, so the conversation must stay Restricted.');
    }
}
