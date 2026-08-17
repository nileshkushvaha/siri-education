<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class FailedLoginAttemptNotification extends AuthNotification
{
    use Queueable;

    public function __construct(
        private readonly int $remainingAttempts,
        private readonly string $ipAddress,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $appUrl = config('app.url');

        return $this->configureMailMessage(new MailMessage)
            ->subject("Failed login attempt on your {$appName} account")
            ->view('emails.auth.failed-login-attempt', [
                'user' => $notifiable,
                'ipAddress' => $this->ipAddress,
                'remainingAttempts' => $this->remainingAttempts,
                'appName' => $appName,
                'appUrl' => $appUrl,
            ]);
    }
}
