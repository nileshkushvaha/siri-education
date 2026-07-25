<?php

declare(strict_types=1);

namespace App\Notifications\Messaging;

use App\Models\Message;
use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SRS §17.31/requirement #8: never includes the message body in the
 * email preview — a generic "new message" notice with a link back
 * into the platform is the privacy-safe design; the recipient reads
 * the actual content only after authenticating into their own inbox.
 */
final class MessageReceivedNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail, Queueable;

    public function __construct(
        public readonly Message $message,
    ) {
        $this->onQueue('notifications');
    }

    public function emailCategory(): string
    {
        return 'messaging';
    }

    public function senderKey(): string
    {
        return 'support';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('New message from %s', $this->message->sender->name))
            ->line(sprintf('You have a new message from %s.', $this->message->sender->name))
            ->action('View message', route('dashboard.messages.show', $this->message->conversation_id));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New message',
            'message' => sprintf('New message from %s.', $this->message->sender->name),
            'conversation_id' => $this->message->conversation_id,
        ];
    }
}
