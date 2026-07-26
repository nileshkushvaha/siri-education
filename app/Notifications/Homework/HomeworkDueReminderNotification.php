<?php

declare(strict_types=1);

namespace App\Notifications\Homework;

use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use App\Notifications\Homework\Concerns\RoutesHomeworkReminderChannels;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRenderer;
use App\Support\RecipientTimezoneResolver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 24K — GAP-020 (SRS §7.11 / SRS-7-11): sent when a homework
 * assignment approaches its due date. Content is safe and minimal —
 * title, due time in the recipient's timezone, accurate remaining-time
 * wording (computed at send time, never a hardcoded offset label), a
 * safe lesson/plan context label, and a link to the dashboard. No
 * homework response content, no instructor private data, no payment
 * data, no raw internal IDs.
 *
 * Phase 24K.1 — deliberately NOT ShouldQueue: the wrapping
 * SendHomeworkDueReminderJob is already queued and is the sole retry
 * authority. Laravel's own NotificationSender::sendNow() restarts its
 * ENTIRE channel list and assigns a fresh random notification UUID on
 * every call — layering a second, independent ShouldQueue retry cycle
 * on top of the job's own retries would reintroduce exactly the
 * partial-channel duplication this phase closes.
 * HomeworkReminderChannelSender calls Notification::sendNow() with
 * exactly one channel per call, so each channel is fully isolated.
 */
final class HomeworkDueReminderNotification extends Notification implements TransactionalEmail
{
    use ConfiguresTransactionalEmail, RoutesHomeworkReminderChannels;

    public function __construct(
        public readonly HomeworkAssignment $assignment,
    ) {}

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
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::HomeworkDueReminder,
            NotificationTemplateChannel::Mail,
            [
                'homework_title' => $this->assignment->title,
                'due_wording' => $this->remainingTimeWording($notifiable),
                'due_date' => $this->formattedDueAt($notifiable),
                'context_label' => $this->contextLabel() ?? '',
            ],
        );

        $mail = $this->configureMailMessage(new MailMessage)->subject($rendered->subject);

        foreach ($rendered->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('View homework', route('dashboard.homework'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::HomeworkDueReminder,
            NotificationTemplateChannel::Database,
            [
                'homework_title' => $this->assignment->title,
                'due_wording' => $this->remainingTimeWording($notifiable),
                'due_date' => $this->formattedDueAt($notifiable),
            ],
        );

        return [
            'title' => $rendered->subject,
            'message' => $rendered->message(),
        ];
    }

    protected function plainText(): string
    {
        return sprintf('Homework "%s" is due %s.', $this->assignment->title, $this->remainingTimeWording($this->assignment->student));
    }

    private function formattedDueAt(object $notifiable): string
    {
        $timezone = $notifiable instanceof User
            ? RecipientTimezoneResolver::resolve($notifiable)
            : RecipientTimezoneResolver::PLATFORM_FALLBACK;

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
