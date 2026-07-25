<?php

declare(strict_types=1);

namespace App\Notifications\SupportCase;

use App\Models\SupportCase;
use App\Models\SupportCaseReply;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * Only ever dispatched for a requester-visible reply (guarded by the
 * listener) — an internal note must never reach here (SRS §25.19).
 */
final class SupportCaseReplyNotification extends SupportCaseNotification
{
    use Queueable;

    public function __construct(
        public readonly SupportCase $case,
        public readonly SupportCaseReply $reply,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('New reply on support case %s', $this->case->case_number))
            ->line(sprintf('There is a new reply on support case "%s".', $this->case->subject))
            ->line(Str::limit($this->reply->visibility === SupportCaseReplyVisibility::RequesterVisible ? $this->reply->body : '', 500))
            ->action('View case', route('dashboard.support-cases.show', $this->case));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New reply on your support case',
            'message' => sprintf('New reply on support case %s.', $this->case->case_number),
            'case_id' => $this->case->id,
            'case_number' => $this->case->case_number,
        ];
    }
}
