<?php

declare(strict_types=1);

namespace App\Notifications\Instructor;

use App\Earnings\Enums\PayoutMethodStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Instructor-facing payout-method outcome. Carries only the safe
 * display label and (for rejections) the admin-provided reason — never
 * account numbers, routing data, or any encrypted payload.
 */
final class InstructorPayoutMethodStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PayoutMethodStatus $status,
        private readonly string $displayLabel,
        private readonly ?string $reason = null,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->status) {
            PayoutMethodStatus::Verified => 'Your payout method has been verified',
            PayoutMethodStatus::Rejected => 'Your payout method could not be verified',
            PayoutMethodStatus::Disabled => 'Your payout method has been disabled',
            default => 'Your payout method status has been updated',
        };

        $message = match ($this->status) {
            PayoutMethodStatus::Verified => sprintf('Good news! Your payout method (%s) has been verified and can now receive withdrawals.', $this->displayLabel),
            PayoutMethodStatus::Rejected => sprintf('Your payout method (%s) could not be verified. Please review the reason below, correct the details, and resubmit.', $this->displayLabel),
            PayoutMethodStatus::Disabled => sprintf('Your payout method (%s) has been disabled and can no longer be used for new withdrawals.', $this->displayLabel),
            default => sprintf('Your payout method (%s) is now: %s.', $this->displayLabel, $this->status->label()),
        };

        $mail = (new MailMessage)
            ->subject($subject.' — '.config('app.name'))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($message);

        if ($this->reason !== null && $this->status === PayoutMethodStatus::Rejected) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail
            ->action('Manage Payout Methods', route('dashboard.instructor.payout-methods'))
            ->line('Thank you for teaching with '.config('app.name').'.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'status' => $this->status->value,
            'message' => sprintf('Payout method %s is now: %s.', $this->displayLabel, $this->status->label()),
            'reason' => $this->status === PayoutMethodStatus::Rejected ? $this->reason : null,
            'url' => route('dashboard.instructor.payout-methods'),
        ];
    }
}
