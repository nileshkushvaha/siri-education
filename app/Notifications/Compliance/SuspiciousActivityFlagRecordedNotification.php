<?php

declare(strict_types=1);

namespace App\Notifications\Compliance;

use App\Models\SuspiciousActivityFlag;
use App\Notifications\Admin\AdminAlertNotification;
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
        return $this->configureMailMessage(new MailMessage)
            ->subject('New compliance flag requires review')
            ->line(sprintf('A %s-severity compliance flag (%s) was recorded and needs review.', $this->flag->severity->label(), $this->flag->reference))
            ->line(sprintf('Rule: %s.', $this->flag->rule_code->label()))
            ->action('Open compliance queue', route('filament.admin.resources.suspicious-activity-flags.index'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New compliance flag',
            'message' => sprintf('A %s-severity compliance flag (%s) was recorded and needs review.', $this->flag->severity->label(), $this->flag->reference),
            'flag_id' => $this->flag->id,
            'reference' => $this->flag->reference,
            'action_url' => route('filament.admin.resources.suspicious-activity-flags.index'),
        ];
    }
}
