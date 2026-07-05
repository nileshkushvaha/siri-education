<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

final class TransactionalNotificationService
{
    public function routeMail(string $email, Notification $notification): void
    {
        NotificationFacade::route('mail', $email)->notify($notification);
    }
}
