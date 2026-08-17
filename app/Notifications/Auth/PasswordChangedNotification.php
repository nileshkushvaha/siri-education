<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class PasswordChangedNotification extends AuthNotification
{
    use Queueable;

    public function __construct(
        public readonly string $ipAddress,
        public readonly string $changedAt,
    ) {
        $this->queue = 'notifications';
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('Your Password Has Been Changed — '.config('app.name'))
            ->view('emails.auth.password-changed', [
                'user' => $notifiable,
                'ipAddress' => $this->ipAddress,
                'changedAt' => $this->changedAt,
                'appName' => config('app.name'),
                'appUrl' => config('app.url'),
            ]);
    }
}
