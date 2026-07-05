<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class WelcomeNotification extends AuthNotification
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications')->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('Welcome to '.config('app.name').'! 🎉')
            ->view('emails.auth.welcome', [
                'user' => $notifiable,
                'appName' => config('app.name'),
                'appUrl' => config('app.url'),
            ]);
    }
}
