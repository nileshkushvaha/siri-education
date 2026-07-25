<?php

declare(strict_types=1);

namespace App\Notifications\SupportCase;

use App\Models\SupportCase;
use App\SupportCases\Enums\SupportCaseStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class SupportCaseStatusChangedNotification extends SupportCaseNotification
{
    use Queueable;

    public function __construct(
        public readonly SupportCase $case,
        public readonly SupportCaseStatus $status,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Support case %s: %s', $this->case->case_number, $this->status->label()))
            ->line(sprintf('Your support case "%s" is now: %s.', $this->case->subject, $this->status->label()));

        if ($this->status === SupportCaseStatus::Resolved && $this->case->resolution_summary !== null) {
            $mail->line($this->case->resolution_summary);
        }

        return $mail->action('View case', route('dashboard.support-cases.show', $this->case));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => sprintf('Support case %s', $this->status->label()),
            'message' => sprintf('Support case %s is now %s.', $this->case->case_number, $this->status->label()),
            'case_id' => $this->case->id,
            'case_number' => $this->case->case_number,
            'status' => $this->status->value,
        ];
    }
}
