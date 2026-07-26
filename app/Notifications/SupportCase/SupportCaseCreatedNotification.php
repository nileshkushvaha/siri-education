<?php

declare(strict_types=1);

namespace App\Notifications\SupportCase;

use App\Models\SupportCase;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRenderer;
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
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::SupportCaseCreated,
            NotificationTemplateChannel::Mail,
            ['case_subject' => $this->case->subject, 'case_number' => $this->case->case_number],
        );

        $mail = $this->configureMailMessage(new MailMessage)->subject($rendered->subject);

        foreach ($rendered->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('View case', route('dashboard.support-cases.show', $this->case));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::SupportCaseCreated,
            NotificationTemplateChannel::Database,
            ['case_number' => $this->case->case_number],
        );

        return [
            'title' => $rendered->subject,
            'message' => $rendered->message(),
            'case_id' => $this->case->id,
            'case_number' => $this->case->case_number,
        ];
    }
}
