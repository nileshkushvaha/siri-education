<?php

declare(strict_types=1);

namespace App\Notifications\Waitlist;

use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Base for waitlist notifications. Carries only scalar, non-sensitive
 * values — never another student's identity, contact details, or
 * queue position relative to anyone else.
 */
abstract class WaitlistNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail;

    public function emailCategory(): string
    {
        return 'waitlist';
    }

    public function senderKey(): string
    {
        return 'waitlist';
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
