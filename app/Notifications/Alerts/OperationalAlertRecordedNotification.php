<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Models\OperationalAlert;
use App\Notifications\Admin\AdminAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to permission-authorized administrators for High/Critical
 * severity alerts only (see SendOperationalAlertRecordedNotification).
 * The preview carries only the title/summary the source already
 * marked safe (never credentials, provider payloads, bank details, or
 * private user content) plus the reference.
 */
final class OperationalAlertRecordedNotification extends AdminAlertNotification
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly OperationalAlert $alert,
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
            ->subject(sprintf('%s operational alert: %s', $this->alert->severity->label(), $this->alert->title))
            ->line(sprintf('A %s-severity operational alert (%s) was recorded.', $this->alert->severity->label(), $this->alert->reference))
            ->line($this->alert->summary)
            ->action('Open operational alerts', route('filament.admin.resources.operational-alerts.index'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->alert->title,
            'message' => $this->alert->summary,
            'alert_id' => $this->alert->id,
            'reference' => $this->alert->reference,
            'action_url' => route('filament.admin.resources.operational-alerts.index'),
        ];
    }
}
