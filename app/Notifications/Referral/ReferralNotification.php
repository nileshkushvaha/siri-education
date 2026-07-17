<?php

declare(strict_types=1);

namespace App\Notifications\Referral;

use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Base for referrer-facing reward notifications. Carries only scalar,
 * pre-masked values — never a referred student's email/phone, payment
 * details, lesson internals, or fraud reasoning. The 'referral' sender
 * key falls back to the platform default sender until configured.
 */
abstract class ReferralNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail;

    public function emailCategory(): string
    {
        return 'referral';
    }

    public function senderKey(): string
    {
        return 'referral';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => Str::headline(Str::beforeLast(class_basename(static::class), 'Notification')),
            'message' => $this->plainText(),
            ...$this->databaseContext(),
        ];
    }

    abstract protected function plainText(): string;

    /** @return array<string, mixed> */
    protected function databaseContext(): array
    {
        return [];
    }
}
