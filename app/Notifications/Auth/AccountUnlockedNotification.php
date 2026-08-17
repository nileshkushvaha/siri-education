<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class AccountUnlockedNotification extends AuthNotification
{
    use Queueable;

    public function __construct(
        private readonly string $method = 'auto', // 'auto' | 'admin' | 'self_service'
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

        $subject = match ($this->method) {
            'auto' => "Your {$appName} account has been automatically unlocked ✅",
            'self_service' => "Your {$appName} account has been unlocked ✅",
            default => "Your {$appName} account has been unlocked by an administrator ✅",
        };

        $body = match ($this->method) {
            'auto' => 'The temporary lock on your account has expired and you can now sign in again.',
            'self_service' => 'You have successfully unlocked your account. You can now sign in.',
            default => 'An administrator has unlocked your account. You can now sign in.',
        };

        return $this->configureMailMessage(new MailMessage)
            ->subject($subject)
            ->greeting('Good news, '.$notifiable->first_name.'!')
            ->line($body)
            ->action('Sign In', route('auth.login'))
            ->line("If you did not expect this, please contact {$appName} support immediately.")
            ->line("— The {$appName} Team");
    }
}
