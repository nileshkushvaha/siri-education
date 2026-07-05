<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Services\Mail\EmailLogService;
use Illuminate\Notifications\Events\NotificationSending;

final class LogNotificationSending
{
    public function __construct(private readonly EmailLogService $logs) {}

    public function handle(NotificationSending $event): void
    {
        $this->logs->markNotificationPending($event);
    }
}
