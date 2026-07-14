<?php

declare(strict_types=1);

namespace App\Notifications\Quality;

use App\Models\InstructorQualityAlert;
use App\Notifications\Admin\AdminAlertNotification;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to permission-authorized administrators. One generic
 * notification covers every alert type (low-rating and every other
 * instructor-quality concern) — never a separate template per type.
 * Never includes student identity, raw review/report text, attendance-
 * provider metadata, financial information, or instructor
 * compensation; the instructor reference is a staff-facing full
 * identity (the same identity staff already see on every other Phase
 * 17O queue row), not the public-facing masked label. The instructor
 * themself is never notified about their own internal alert.
 */
final class InstructorQualityAlertCreatedNotification extends AdminAlertNotification
{
    use Queueable, RoutesReviewChannels, SerializesModels;

    public function __construct(
        public readonly InstructorQualityAlert $alert,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('New instructor quality alert')
            ->line(sprintf('A %s quality alert (%s severity) was recorded for %s.', $this->alert->alert_type->label(), $this->alert->severity->label(), $this->alert->instructor?->name ?? 'an instructor'))
            ->line(sprintf('Triggered on %s.', $this->alert->triggered_at->format('M j, Y g:i A')))
            ->action('Open quality-alert queue', route('filament.admin.pages.reports.reviews-quality'));
    }

    protected function plainText(): string
    {
        return sprintf('A %s quality alert (%s severity) was recorded for %s.', $this->alert->alert_type->label(), $this->alert->severity->label(), $this->alert->instructor?->name ?? 'an instructor');
    }

    protected function databaseContext(): array
    {
        return [
            'alert_id' => $this->alert->id,
            'instructor_id' => $this->alert->instructor_id,
            'action_url' => route('filament.admin.pages.reports.reviews-quality'),
        ];
    }
}
