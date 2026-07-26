<?php

declare(strict_types=1);

namespace App\Notifications\Instructor;

use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Informs the instructor a demo-to-paid conversion bonus was earned.
 * Carries only the formatted amount and
 * award id — never the student's identity, the demo/paid lesson
 * internals, or the rule snapshot. Mirrors
 * InstructorWithdrawalStatusNotification's plain (non-templated)
 * instructor-financial-notice shape.
 */
final class DemoConversionIncentiveEarnedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $awardId,
        private readonly int $amountMinor,
        private readonly string $currencyCode,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = MoneyFormatter::format($this->amountMinor, $this->currencyCode);

        return (new MailMessage)
            ->subject('You earned a conversion bonus — '.config('app.name'))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line(sprintf('One of your students booked a paid lesson after their demo — you earned a bonus of %s.', $amount))
            ->action('View Earnings', route('dashboard.instructor.earnings'))
            ->line('Thank you for teaching with '.config('app.name').'.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'award_id' => $this->awardId,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currencyCode,
            'message' => sprintf('You earned a demo-to-paid conversion bonus of %s.', MoneyFormatter::format($this->amountMinor, $this->currencyCode)),
            'url' => route('dashboard.instructor.earnings'),
        ];
    }
}
