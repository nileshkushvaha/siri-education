<?php

declare(strict_types=1);

namespace App\Notifications\Package;

use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for package-purchase notifications, mirroring
 * BookingNotification exactly: queued, routed through the shared
 * transactional-mail sender, and tagged with its own email category so
 * Email Logs can tell package mail from booking mail.
 */
abstract class PackageNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail;

    public function emailCategory(): string
    {
        return 'package';
    }

    public function senderKey(): string
    {
        return 'booking';
    }
}
