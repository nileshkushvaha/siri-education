<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Listeners\Messaging\SendMessagingNotifications;
use App\Messaging\Events\MessageSent;
use App\Messaging\Services\MessagingService;
use App\Models\Message;
use App\Notifications\Messaging\MessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * SRS §17.42/requirement #8: queued, after-commit, idempotent,
 * privacy-safe, and only for new unread messages.
 */
class MessagingNotificationTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    public function test_the_other_participant_receives_a_notification_end_to_end(): void
    {
        Notification::fake();

        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);

        $service->send($conversation, $student, 'Hello!');

        Notification::assertSentTo($instructor, MessageReceivedNotification::class, 1);
        Notification::assertNotSentTo($student, MessageReceivedNotification::class);
    }

    public function test_a_redelivered_event_never_double_sends(): void
    {
        Notification::fake();

        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $message = $service->send($conversation, $student, 'Hello!');

        $listener = app(SendMessagingNotifications::class);
        $listener->handleMessageSent(new MessageSent($message));
        $listener->handleMessageSent(new MessageSent($message)); // simulated redelivery

        Notification::assertSentToTimes($instructor, MessageReceivedNotification::class, 1);
    }

    public function test_email_preview_never_includes_the_private_message_body(): void
    {
        Notification::fake();

        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $secret = 'PRIVATE_SECRET_MESSAGE_CONTENT';

        $service->send($conversation, $student, $secret);

        Notification::assertSentTo($instructor, function (MessageReceivedNotification $notification) use ($secret, $instructor): bool {
            $mail = $notification->toMail($instructor);
            $rendered = implode(' ', [...$mail->introLines, ...$mail->outroLines, (string) $mail->subject]);

            return ! str_contains($rendered, $secret);
        });
    }

    public function test_no_notification_is_sent_if_the_message_is_already_read_by_dispatch_time(): void
    {
        Notification::fake();

        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        // Built directly (not via ->send()) so the real MessageSent
        // listener never auto-fires for this message — isolating the
        // "already read by dispatch time" race the listener itself
        // guards against, from send()'s own real-time notification.
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $student->id,
            'body' => 'Hello!',
            'read_at' => now(),
        ]);

        app(SendMessagingNotifications::class)->handleMessageSent(new MessageSent($message));

        Notification::assertNothingSent();
    }
}
