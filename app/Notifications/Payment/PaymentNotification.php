<?php

declare(strict_types=1);

namespace App\Notifications\Payment;

use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class PaymentNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail;

    public function emailCategory(): string
    {
        return 'payment';
    }

    public function senderKey(): string
    {
        return 'payment';
    }
}
