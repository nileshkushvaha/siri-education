<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\LessonReview;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms receipt to the submitting student — never the raw review
 * text, sanitization matches, or internal moderation flags, only a
 * neutral state description.
 */
final class ReviewSubmittedNotification extends ReviewNotification
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
            ->subject('We received your review')
            ->line('Thank you — we received your review.')
            ->line($this->statusLine())
            ->action('View your reviews', route('dashboard.reviews'));
    }

    protected function plainText(): string
    {
        return sprintf('We received your review. %s', $this->statusLine());
    }

    protected function databaseContext(): array
    {
        return [
            'review_id' => $this->review->id,
            'action_url' => route('dashboard.reviews'),
        ];
    }

    private function statusLine(): string
    {
        if ($this->review->review_mode === LessonReviewEligibilityMode::PrivateFeedback) {
            return 'It has been shared privately and will not appear publicly.';
        }

        return 'It is currently awaiting moderation before it can be published.';
    }
}
