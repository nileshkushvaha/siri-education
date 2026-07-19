<?php

declare(strict_types=1);

namespace App\Notifications\Homework\Concerns;

use App\Homework\Services\HomeworkNotificationChannelResolver;
use Illuminate\Support\Str;

/** Mirrors Booking's RoutesBookingChannels for homework notifications. */
trait RoutesHomeworkReminderChannels
{
    public function via(object $notifiable): array
    {
        return app(HomeworkNotificationChannelResolver::class)->channels($notifiable);
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->plainText();
    }

    public function toSms(object $notifiable): string
    {
        return $this->plainText();
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => Str::headline(Str::beforeLast(class_basename(static::class), 'Notification')),
            'message' => $this->plainText(),
        ];
    }

    abstract protected function plainText(): string;
}
