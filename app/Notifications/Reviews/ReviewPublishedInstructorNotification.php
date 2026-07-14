<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\LessonReview;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the reviewed instructor for PUBLIC reviews only — the
 * listener never dispatches this for private feedback. No student
 * identity, contact detail, moderation history, or report data is
 * ever included; the rating alone is not sensitive.
 */
final class ReviewPublishedInstructorNotification extends ReviewNotification
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
            ->subject('A new review has been published on your profile')
            ->line('A new verified review has been published on your profile.')
            ->line(sprintf('Overall rating: %d ★', $this->review->overall_rating))
            ->action('View your quality insights', route('dashboard.instructor.quality-insights'));
    }

    protected function plainText(): string
    {
        return 'A new verified review has been published on your profile.';
    }

    protected function databaseContext(): array
    {
        return [
            'review_id' => $this->review->id,
            'review_version' => $this->review->version,
            'action_url' => route('dashboard.instructor.quality-insights'),
        ];
    }
}
