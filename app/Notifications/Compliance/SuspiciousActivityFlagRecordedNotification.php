<?php

declare(strict_types=1);

namespace App\Notifications\Compliance;

use App\Models\SuspiciousActivityFlag;
use App\Notifications\Admin\AdminAlertNotification;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to permission-authorized administrators for warning/critical-
 * severity flags only (see SendSuspiciousActivityFlagRecordedNotification).
 * A flag is evidence for human review, never proof of fraud — the
 * preview intentionally carries only the rule name, severity, and
 * reference, never the evidence snapshot, subject identity details
 * beyond a name, or any narrative text.
 */
final class SuspiciousActivityFlagRecordedNotification extends AdminAlertNotification
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SuspiciousActivityFlag $flag,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::SuspiciousActivityFlagged,
            NotificationTemplateChannel::Mail,
            [
                'severity' => $this->flag->severity->label(),
                'reference' => $this->flag->reference,
                'rule_name' => $this->flag->rule_code->label(),
            ],
        );

        $mail = $this->configureMailMessage(new MailMessage)->subject($rendered->subject);

        foreach ($rendered->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('Open compliance queue', route('filament.admin.resources.suspicious-activity-flags.index'));
    }

    public function toDatabase(object $notifiable): array
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::SuspiciousActivityFlagged,
            NotificationTemplateChannel::Database,
            ['severity' => $this->flag->severity->label(), 'reference' => $this->flag->reference],
        );

        return [
            'title' => $rendered->subject,
            'message' => $rendered->message(),
            'flag_id' => $this->flag->id,
            'reference' => $this->flag->reference,
            'action_url' => route('filament.admin.resources.suspicious-activity-flags.index'),
        ];
    }
}
