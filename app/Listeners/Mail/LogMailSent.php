<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Services\Mail\EmailLogService;
use Illuminate\Mail\Events\MessageSent;

final class LogMailSent
{
    public function __construct(private readonly EmailLogService $logs) {}

    public function handle(MessageSent $event): void
    {
        $this->logs->recordSent($event);
    }
}
