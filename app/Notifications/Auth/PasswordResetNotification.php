<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class PasswordResetNotification extends AuthNotification
{
    use Queueable;

    public function __construct(
        public readonly string $resetUrl,
        public readonly int $expireMinutes = 60,
    ) {
        $this->queue = 'notifications';
        $this->afterCommit();
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('Reset Your Password — '.config('app.name'))
            ->view('emails.auth.password-reset', [
                'url' => $this->resetUrl,
                'expireMinutes' => $this->expireMinutes,
                'appName' => config('app.name'),
                'appUrl' => config('app.url'),
            ]);
    }
}
