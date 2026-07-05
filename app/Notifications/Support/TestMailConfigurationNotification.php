<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class TestMailConfigurationNotification extends SupportNotification
{
    use Queueable;

    public function __construct(
        private readonly string $appName,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('Test Email - Mail Configuration')
            ->line("This is a test email from {$this->appName}. Your mail configuration is working correctly.");
    }
}
