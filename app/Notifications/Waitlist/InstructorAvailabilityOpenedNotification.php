<?php

declare(strict_types=1);

namespace App\Notifications\Waitlist;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SRS §10.31/§10.33-4: "Waitlist notifications shall not guarantee
 * booking; they only notify students of availability" — the wording
 * here is deliberately informational, never a reservation or a
 * deadline, and never names or counts any other waitlisted student.
 */
final class InstructorAvailabilityOpenedNotification extends WaitlistNotification
{
    use Queueable;

    public function __construct(
        public readonly int $instructorId,
        public readonly string $instructorName,
        public readonly string $instructorSlug,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('%s has new availability', $this->instructorName))
            ->line($this->plainText())
            ->action('View availability', route('instructors.show', $this->instructorSlug));
    }

    protected function plainText(): string
    {
        return sprintf(
            '%s now has new availability. This is first-come, first-served and does not guarantee a slot — book now to secure your preferred time.',
            $this->instructorName,
        );
    }

    protected function databaseContext(): array
    {
        return [
            'instructor_id' => $this->instructorId,
            'action_url' => route('instructors.show', $this->instructorSlug),
        ];
    }
}
