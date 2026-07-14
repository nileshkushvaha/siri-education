<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\LessonReviewEligibility;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/** Sent to the eligible student when a review-eligibility window opens. */
final class ReviewRequestedNotification extends ReviewNotification
{
    use Queueable, RoutesReviewChannels, SerializesModels;

    public function __construct(
        public readonly LessonReviewEligibility $eligibility,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('A completed lesson is ready for your review')
            ->line('A completed lesson is ready for your review.')
            ->action('Review your lesson', route('dashboard.reviews'))
            ->line(sprintf('This opportunity is available until %s.', $this->eligibility->expires_at->format('M j, Y')));
    }

    protected function plainText(): string
    {
        return 'A completed lesson is ready for your review.';
    }

    protected function databaseContext(): array
    {
        return [
            'eligibility_id' => $this->eligibility->id,
            'action_url' => route('dashboard.reviews'),
        ];
    }
}
