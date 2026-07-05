<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Services\Mail\EmailLogService;
use Illuminate\Notifications\Events\NotificationFailed;

final class LogNotificationFailed
{
    public function __construct(private readonly EmailLogService $logs) {}

    public function handle(NotificationFailed $event): void
    {
        $this->logs->markNotificationFailed($event);
    }
}
