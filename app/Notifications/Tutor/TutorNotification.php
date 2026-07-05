<?php

declare(strict_types=1);

namespace App\Notifications\Tutor;

use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class TutorNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail;

    public function emailCategory(): string
    {
        return 'tutor';
    }

    public function senderKey(): string
    {
        return 'tutor';
    }
}
