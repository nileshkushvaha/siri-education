<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class NewDeviceLoginNotification extends AuthNotification
{
    use Queueable;

    public function __construct(
        private readonly string $ipAddress,
        private readonly string $browser,
        private readonly string $platform,
        private readonly string $loginAt,
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
            ->subject("New device signed in to your {$appName} account")
            ->view('emails.auth.new-device-login', [
                'user' => $notifiable,
                'ipAddress' => $this->ipAddress,
                'browser' => $this->browser,
                'platform' => $this->platform,
                'loginAt' => $this->loginAt,
                'appName' => $appName,
                'appUrl' => $appUrl,
                'secureUrl' => route('auth.password.request'),
            ]);
    }
}
