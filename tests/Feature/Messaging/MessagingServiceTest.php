<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Booking\Enums\BookingStatus;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Messaging\Enums\ConversationStatus;
use App\Messaging\Exceptions\MessagingException;
use App\Messaging\Services\MessagingService;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * SRS §17.28-§17.36: opening/finding a conversation
 * (duplicate prevention), sending, closing, and immutable message
 * content.
 */
class MessagingServiceTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    public function test_opening_a_conversation_requires_eligibility(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();

        $this->expectException(MessagingException::class);
        app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);
    }

    public function test_opening_an_eligible_conversation_succeeds(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $booking = $this->confirmedPaidBooking($student, $instructor);

        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $this->assertSame($student->id, $conversation->student_id);
        $this->assertSame($instructor->id, $conversation->instructor_id);
        $this->assertSame($booking->id, $conversation->context_id);
        $this->assertSame(ConversationStatus::Active, $conversation->status);
    }

    public function test_duplicate_conversations_are_never_created_for_the_same_participants_and_context(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);

        $service = app(MessagingService::class);
        $first = $service->openOrFindConversation($student, $instructor, $student);
        $second = $service->openOrFindConversation($student, $instructor, $instructor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Conversation::query()->count());
    }

    public function test_sending_a_message_updates_last_message_at(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $message = app(MessagingService::class)->send($conversation, $student, 'Hello!');

        $this->assertSame('Hello!', $message->body);
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_a_non_participant_cannot_send_a_message(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);
        $intruder = $this->student();

        $this->expectException(MessagingException::class);
        app(MessagingService::class)->send($conversation, $intruder, 'Sneaky message');
    }

    public function test_a_closed_conversation_rejects_new_messages(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $service->close($conversation, $student);

        $this->expectException(MessagingException::class);
        $service->send($conversation, $student, 'Still there?');
    }

    public function test_sending_becomes_ineligible_once_the_booking_is_cancelled(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $booking = $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $booking->update(['status' => BookingStatus::Cancelled]);

        $this->expectException(MessagingException::class);
        app(MessagingService::class)->send($conversation, $student, 'Still eligible?');
    }

    public function test_a_message_cannot_be_hard_deleted(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);
        $message = app(MessagingService::class)->send($conversation, $student, 'Hello');

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $message->delete();
    }

    public function test_marking_read_only_affects_messages_from_the_other_participant(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);

        $studentMessage = $service->send($conversation, $student, 'From student');
        $instructorMessage = $service->send($conversation, $instructor, 'From instructor');

        $count = $service->markRead($conversation, $student);

        $this->assertSame(1, $count);
        $this->assertNull($studentMessage->fresh()->read_at, "A sender's own message is never marked read by their own markRead call.");
        $this->assertNotNull($instructorMessage->fresh()->read_at);
    }

    public function test_close_is_idempotent(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);

        $service->close($conversation, $student);
        $closedAt = $conversation->fresh()->closed_at;
        $service->close($conversation, $student);

        $this->assertSame($closedAt->toIso8601String(), $conversation->fresh()->closed_at->toIso8601String());
    }

    public function test_concurrent_sends_do_not_corrupt_conversation_state(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);

        // Two in-memory copies simulating two concurrent requests.
        $copyA = Conversation::query()->findOrFail($conversation->id);
        $copyB = Conversation::query()->findOrFail($conversation->id);

        $service->send($copyA, $student, 'First');
        $service->send($copyB, $instructor, 'Second');

        $this->assertSame(2, Message::query()->where('conversation_id', $conversation->id)->count());
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }
}
