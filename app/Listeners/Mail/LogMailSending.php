<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Services\Mail\EmailLogService;
use Illuminate\Mail\Events\MessageSending;

final class LogMailSending
{
    public function __construct(private readonly EmailLogService $logs) {}

    public function handle(MessageSending $event): void
    {
        $this->logs->recordSending($event);
    }
}
