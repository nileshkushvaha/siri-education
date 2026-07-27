<?php

declare(strict_types=1);

namespace App\Listeners\Messaging;

use App\Messaging\Events\MessageSent;
use App\Notifications\Messaging\MessageReceivedNotification;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Participant notification for messaging (SRS §17.42
 * "Controlled Student-Instructor Message" workflow). Requirement #8:
 * "sent only for new unread messages" — re-checks read_at at
 * dispatch time (a queued job can run seconds after commit; if the
 * recipient already opened the conversation and read it by then,
 * skip). Admin awareness of reports flows separately through the
 * Activity Log pipeline (NotificationMapper), matching every other
 * domain in this codebase.
 */
final class SendMessagingNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handleMessageSent(MessageSent $event): void
    {
        $message = $event->message->fresh();

        if ($message === null || $message->read_at !== null) {
            return;
        }

        $conversation = $message->conversation;
        $recipient = $conversation->otherParticipant($message->sender);

        if ($recipient === null) {
            return;
        }

        $key = sprintf('message-received:%s:%d', $message->id, $recipient->id);

        $this->idempotency->once($key, MessageReceivedNotification::class, function () use ($recipient, $message): void {
            $recipient->notify(new MessageReceivedNotification($message));
        });
    }
}
