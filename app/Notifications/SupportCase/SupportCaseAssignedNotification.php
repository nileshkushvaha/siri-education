<?php

declare(strict_types=1);

namespace App\Notifications\SupportCase;

use App\Models\SupportCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class SupportCaseAssignedNotification extends SupportCaseNotification
{
    use Queueable;

    public function __construct(
        public readonly SupportCase $case,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Support case %s assigned to you', $this->case->case_number))
            ->line(sprintf('Support case "%s" has been assigned to you.', $this->case->subject))
            ->line(sprintf('Reference: %s · Priority: %s', $this->case->case_number, $this->case->priority->label()));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Support case assigned to you',
            'message' => sprintf('Support case %s has been assigned to you.', $this->case->case_number),
            'case_id' => $this->case->id,
            'case_number' => $this->case->case_number,
        ];
    }
}
