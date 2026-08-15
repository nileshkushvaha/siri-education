<?php

declare(strict_types=1);

namespace App\Notifications\Reviews;

use App\Models\LessonReviewEligibility;
use App\Notifications\Concerns\FormatsRecipientLocalTime;
use App\Notifications\Reviews\Concerns\RoutesReviewChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/** Sent to the eligible student when a review-eligibility window opens. */
final class ReviewRequestedNotification extends ReviewNotification
{
    use FormatsRecipientLocalTime, Queueable, RoutesReviewChannels, SerializesModels;

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
            // TZ-3: a DEADLINE, and the highest-value fix in this
            // phase — an expiry at 23:30 UTC is "Aug 15" for a student
            // in Los Angeles and "Aug 16" for one in Kolkata, so the
            // old server-timezone rendering told half the world the
            // wrong day to act by. The instant is untouched; only the
            // date the student reads changes.
            ->line(sprintf(
                'This opportunity is available until %s.',
                $this->recipientDate($this->eligibility->expires_at, $notifiable),
            ));
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
