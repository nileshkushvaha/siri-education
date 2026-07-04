<?php

declare(strict_types=1);

namespace App\Notifications\Newsletter;

use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewsletterWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return (new MailMessage)
            ->subject('You\'re subscribed!')
            ->greeting('Welcome'.($this->subscriber->name ? ', '.$this->subscriber->name : '').'!')
            ->line('You are now subscribed to our newsletter.')
            ->action('Unsubscribe', route('newsletter.unsubscribe', $this->subscriber->unsubscribe_token))
            ->line('If you did not request this, you can safely ignore this email or unsubscribe using the link above.');
    }
}
