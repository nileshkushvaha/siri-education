<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\ReviewReport;
use App\Notifications\Admin\AdminAlertNotification;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to permission-authorized report reviewers. Never includes the
 * reporter's identity, the raw explanation text, student contact
 * information, review text, or internal audit data — only the review
 * reference, the report reason's enum label, the review's current
 * status, and the report's submission timestamp.
 */
final class ReviewReportedNotification extends AdminAlertNotification
{
    use Queueable, RoutesReviewChannels, SerializesModels;

    public function __construct(
        public readonly ReviewReport $report,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('A review has been reported')
            ->line(sprintf('A review was reported for: %s.', $this->report->reason->label()))
            ->line(sprintf('Current review status: %s.', $this->report->review->status->label()))
            ->line(sprintf('Reported on %s.', $this->report->submitted_at->format('M j, Y g:i A')))
            ->action('Open report queue', route('filament.admin.pages.reports.reviews-quality'));
    }

    protected function plainText(): string
    {
        return sprintf('A review was reported for: %s.', $this->report->reason->label());
    }

    protected function databaseContext(): array
    {
        return [
            'report_id' => $this->report->id,
            'review_id' => $this->report->review_id,
            'action_url' => route('filament.admin.pages.reports.reviews-quality'),
        ];
    }
}
