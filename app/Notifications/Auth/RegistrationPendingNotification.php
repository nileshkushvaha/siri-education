<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class RegistrationPendingNotification extends AuthNotification
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
            ->subject('Your '.config('app.name').' account is pending approval')
            ->view('emails.auth.registration-pending', [
                'user' => $notifiable,
                'appName' => config('app.name'),
                'appUrl' => config('app.url'),
            ]);
    }
}
