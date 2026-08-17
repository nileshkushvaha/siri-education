<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Settings\AccountProtectionSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class AccountLockedNotification extends AuthNotification
{
    use Queueable;

    public function __construct(
        private readonly string $unlockToken,
        private readonly int $attempts = 0,
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
        $unlockUrl = url(route('auth.account.unlock', [
            'token' => $this->unlockToken,
            'email' => $notifiable->email,
        ]));

        $appName = config('app.name');
        $appUrl = config('app.url');

        return $this->configureMailMessage(new MailMessage)
            ->subject("Your {$appName} account has been locked 🔒")
            ->view('emails.auth.account-locked', [
                'user' => $notifiable,
                'unlockUrl' => $unlockUrl,
                'appName' => $appName,
                'appUrl' => $appUrl,
                'minutes' => app(AccountProtectionSettings::class)->auto_unlock_after,
                'attempts' => $this->attempts,
            ]);
    }
}
