<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\LessonReview;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/** Sent only to the review's own student author — no unrelated user is ever notified. */
final class ReviewHiddenNotification extends ReviewNotification
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
            ->subject('Your review is no longer publicly visible')
            ->line('Your review is no longer publicly visible.')
            ->action('View your reviews', route('dashboard.reviews'));
    }

    protected function plainText(): string
    {
        return 'Your review is no longer publicly visible.';
    }

    protected function databaseContext(): array
    {
        return [
            'review_id' => $this->review->id,
            'action_url' => route('dashboard.reviews'),
        ];
    }
}
