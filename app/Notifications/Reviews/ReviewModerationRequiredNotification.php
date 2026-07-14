<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\LessonReview;
use App\Notifications\Admin\AdminAlertNotification;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to permission-authorized moderators when a review genuinely
 * requires human action (Submitted under pre-moderation/disabled
 * auto-publish, or Flagged). One notification per review version —
 * a Flagged review never also produces a separate duplicate
 * "flagged" notification, since this is the only listener that
 * dispatches on this event. Private flagged feedback may notify
 * moderators that action is needed but never includes its content.
 */
final class ReviewModerationRequiredNotification extends AdminAlertNotification
{
    use Queueable, RoutesReviewChannels, SerializesModels;

    public function __construct(
        public readonly LessonReview $review,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('A review requires moderation')
            ->line(sprintf('A %s review requires moderation.', $this->review->review_mode->label()))
            ->line(sprintf('Current status: %s.', $this->review->status->label()))
            ->action('Open moderation queue', route('filament.admin.pages.reports.reviews-quality'));
    }

    protected function plainText(): string
    {
        return sprintf('A %s review requires moderation (status: %s).', $this->review->review_mode->label(), $this->review->status->label());
    }

    protected function databaseContext(): array
    {
        return [
            'review_id' => $this->review->id,
            'review_version' => $this->review->version,
            'action_url' => route('filament.admin.pages.reports.reviews-quality'),
        ];
    }
}
