<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Services\Mail\EmailLogService;
use Illuminate\Notifications\Events\NotificationSent;

final class LogNotificationSent
{
    public function __construct(private readonly EmailLogService $logs) {}

    public function handle(NotificationSent $event): void
    {
        $this->logs->markNotificationSent($event);
    }
}
