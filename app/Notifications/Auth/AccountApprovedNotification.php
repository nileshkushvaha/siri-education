<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class AccountApprovedNotification extends AuthNotification
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
            ->subject('Your '.config('app.name').' account has been approved!')
            ->view('emails.auth.account-approved', [
                'user' => $notifiable,
                'appName' => config('app.name'),
                'appUrl' => config('app.url'),
            ]);
    }
}
