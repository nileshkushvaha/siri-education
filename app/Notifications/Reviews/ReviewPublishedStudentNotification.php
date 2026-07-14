<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\LessonReview;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

final class ReviewPublishedStudentNotification extends ReviewNotification
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
            ->subject('Your review has been published')
            ->line('Your review has been approved and published.')
            ->action('View your reviews', route('dashboard.reviews'));
    }

    protected function plainText(): string
    {
        return 'Your review has been approved and published.';
    }

    protected function databaseContext(): array
    {
        return [
            'review_id' => $this->review->id,
            'review_version' => $this->review->version,
            'action_url' => route('dashboard.reviews'),
        ];
    }
}
