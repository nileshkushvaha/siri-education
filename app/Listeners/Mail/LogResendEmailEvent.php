<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Services\Mail\EmailLogService;
use Resend\Laravel\Events\EmailBounced;
use Resend\Laravel\Events\EmailComplained;
use Resend\Laravel\Events\EmailDelivered;
use Resend\Laravel\Events\EmailDeliveryDelayed;
use Resend\Laravel\Events\EmailFailed;
use Resend\Laravel\Events\EmailSent;
use Resend\Laravel\Events\EmailSuppressed;

final class LogResendEmailEvent
{
    public function __construct(private readonly EmailLogService $logs) {}

    public function handleEmailSent(EmailSent $event): void
    {
        $this->logs->recordProviderEvent($event->payload, 'sent');
    }

    public function handleEmailDelivered(EmailDelivered $event): void
    {
        $this->logs->recordProviderEvent($event->payload, 'delivered');
    }

    public function handleEmailFailed(EmailFailed $event): void
    {
        $this->logs->recordProviderEvent($event->payload, 'failed');
    }

    public function handleEmailBounced(EmailBounced $event): void
    {
        $this->logs->recordProviderEvent($event->payload, 'bounced');
    }

    public function handleEmailComplained(EmailComplained $event): void
    {
        $this->logs->recordProviderEvent($event->payload, 'complained');
    }

    public function handleEmailDelayed(EmailDeliveryDelayed $event): void
    {
        $this->logs->recordProviderEvent($event->payload, 'delayed');
    }

    public function handleEmailSuppressed(EmailSuppressed $event): void
    {
        $this->logs->recordProviderEvent($event->payload, 'suppressed');
    }
}
