<?php

declare(strict_types=1);

namespace App\Notifications\SupportCase;

use App\Models\SupportCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class SupportCaseCreatedNotification extends SupportCaseNotification
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
            ->subject(sprintf('Support case %s received', $this->case->case_number))
            ->line(sprintf('Your support case "%s" has been received.', $this->case->subject))
            ->line(sprintf('Reference: %s', $this->case->case_number))
            ->action('View case', route('dashboard.support-cases.show', $this->case));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Support case created',
            'message' => sprintf('Your support case %s has been received.', $this->case->case_number),
            'case_id' => $this->case->id,
            'case_number' => $this->case->case_number,
        ];
    }
}
