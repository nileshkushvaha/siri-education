<?php

declare(strict_types=1);

namespace App\Notifications\Newsletter;

use App\Models\NewsletterSubscriber;
use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Has no domain base class of its own — newsletter is the only member of
 * its category, so it wires the shared transactional concern directly
 * rather than adding a one-implementation abstract class. `newsletter` has
 * no dedicated sender in MailSettings; TransactionalMailSender falls back
 * to the global from-address for unknown keys, so adding one later is a
 * settings change, not a code change.
 */
final class NewsletterWelcomeNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail;
    use Queueable;

    public function emailCategory(): string
    {
        return 'newsletter';
    }

    public function senderKey(): string
    {
        return 'newsletter';
    }

    public function __construct(
        private readonly NewsletterSubscriber $subscriber,
    ) {
        $this->onQueue('notifications')->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('You\'re subscribed!')
            ->greeting('Welcome'.($this->subscriber->name ? ', '.$this->subscriber->name : '').'!')
            ->line('You are now subscribed to our newsletter.')
            ->action('Unsubscribe', route('newsletter.unsubscribe', $this->subscriber->unsubscribe_token))
            ->line('If you did not request this, you can safely ignore this email or unsubscribe using the link above.');
    }
}
