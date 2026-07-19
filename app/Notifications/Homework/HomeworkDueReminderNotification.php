<?php

declare(strict_types=1);

namespace App\Notifications\Homework;

use App\Homework\Services\HomeworkRecipientTimezoneResolver;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use App\Notifications\Homework\Concerns\RoutesHomeworkReminderChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 24K — GAP-020 (SRS §7.11 / SRS-7-11): sent when a homework
 * assignment approaches its due date. Content is safe and minimal —
 * title, due time in the recipient's timezone, accurate remaining-time
 * wording (computed at send time, never a hardcoded offset label), a
 * safe lesson/plan context label, and a link to the dashboard. No
 * homework response content, no instructor private data, no payment
 * data, no raw internal IDs.
 */
final class HomeworkDueReminderNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail, Queueable, RoutesHomeworkReminderChannels, SerializesModels;

    public function __construct(
        public readonly HomeworkAssignment $assignment,
    ) {
        $this->onQueue('notifications');
    }

    public function emailCategory(): string
    {
        return 'homework';
    }

    public function senderKey(): string
    {
        return 'homework';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Homework due %s', $this->remainingTimeWording($notifiable)))
            ->line(sprintf('"%s" is due %s.', $this->assignment->title, $this->remainingTimeWording($notifiable)))
            ->line(sprintf('Due: %s', $this->formattedDueAt($notifiable)))
            ->when($this->contextLabel() !== null, fn (MailMessage $mail) => $mail->line((string) $this->contextLabel()))
            ->action('View homework', route('dashboard.homework'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Homework due soon',
            'message' => sprintf(
                '"%s" is due %s (%s).',
                $this->assignment->title,
                $this->remainingTimeWording($notifiable),
                $this->formattedDueAt($notifiable),
            ),
        ];
    }

    protected function plainText(): string
    {
        return sprintf('Homework "%s" is due %s.', $this->assignment->title, $this->remainingTimeWording($this->assignment->student));
    }

    private function formattedDueAt(object $notifiable): string
    {
        $timezone = $notifiable instanceof User
            ? HomeworkRecipientTimezoneResolver::resolve($notifiable)
            : HomeworkRecipientTimezoneResolver::PLATFORM_FALLBACK;

        return $this->assignment->due_at->timezone($timezone)->format('D, M j Y \a\t g:i A');
    }

    /**
     * Derived from the actual remaining duration at send time, never a
     * hardcoded "in 24 hours" — a late scheduler run states the true
     * remaining time instead of falsely claiming the configured offset.
     */
    private function remainingTimeWording(object $notifiable): string
    {
        $secondsRemaining = $this->assignment->due_at->getTimestamp() - now()->getTimestamp();
        $minutesRemaining = max(0, (int) floor($secondsRemaining / 60));

        if ($minutesRemaining < 1) {
            return 'very soon';
        }

        if ($minutesRemaining < 60) {
            return sprintf('in %d minute%s', $minutesRemaining, $minutesRemaining === 1 ? '' : 's');
        }

        $hoursRemaining = (int) floor($minutesRemaining / 60);

        if ($hoursRemaining < 48) {
            return sprintf('in %d hour%s', $hoursRemaining, $hoursRemaining === 1 ? '' : 's');
        }

        $daysRemaining = (int) floor($hoursRemaining / 24);

        return sprintf('in %d day%s', $daysRemaining, $daysRemaining === 1 ? '' : 's');
    }

    private function contextLabel(): ?string
    {
        $booking = $this->assignment->booking;
        $plan = $this->assignment->learningPlan;

        return match (true) {
            $booking !== null && $plan !== null => sprintf('Lesson & plan: %s', $plan->title),
            $booking !== null => 'From your recent lesson.',
            $plan !== null => sprintf('Learning plan: %s', $plan->title),
            default => null,
        };
    }
}
