<?php

declare(strict_types=1);

namespace App\Notifications\Instructor;

use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Instructor-facing withdrawal lifecycle updates. Carries the
 * reference, formatted amount, masked destination label, and (for
 * rejections) the safe reason — never bank details, snapshots, or
 * internal review notes.
 */
final class InstructorWithdrawalStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly InstructorWithdrawalStatus $status,
        private readonly string $reference,
        private readonly int $amountMinor,
        private readonly string $currencyCode,
        private readonly string $payoutMethodLabel,
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
        $amount = MoneyFormatter::format($this->amountMinor, $this->currencyCode);

        $subject = match ($this->status) {
            InstructorWithdrawalStatus::Submitted => 'Your withdrawal request has been received',
            InstructorWithdrawalStatus::UnderReview => 'Your withdrawal request is under review',
            InstructorWithdrawalStatus::Approved => 'Your withdrawal request has been approved',
            InstructorWithdrawalStatus::Rejected => 'Your withdrawal request was rejected',
            InstructorWithdrawalStatus::Cancelled => 'Your withdrawal request has been cancelled',
            default => 'Your withdrawal request status has been updated',
        };

        $message = match ($this->status) {
            InstructorWithdrawalStatus::Submitted => sprintf('We received your withdrawal request %s for %s to %s. We will notify you as it progresses.', $this->reference, $amount, $this->payoutMethodLabel),
            InstructorWithdrawalStatus::UnderReview => sprintf('Your withdrawal request %s for %s is now being reviewed.', $this->reference, $amount),
            InstructorWithdrawalStatus::Approved => sprintf('Your withdrawal request %s for %s has been approved. The payout will be processed in an upcoming payout run.', $this->reference, $amount),
            InstructorWithdrawalStatus::Rejected => sprintf('Your withdrawal request %s for %s was rejected. The reserved amount is available again.', $this->reference, $amount),
            InstructorWithdrawalStatus::Cancelled => sprintf('Your withdrawal request %s for %s has been cancelled. The reserved amount is available again.', $this->reference, $amount),
            default => sprintf('Your withdrawal request %s is now: %s.', $this->reference, $this->status->label()),
        };

        $mail = (new MailMessage)
            ->subject($subject.' — '.config('app.name'))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($message);

        if ($this->reason !== null && $this->status === InstructorWithdrawalStatus::Rejected) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail
            ->action('View Withdrawals', route('dashboard.instructor.withdrawals'))
            ->line('Thank you for teaching with '.config('app.name').'.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'status' => $this->status->value,
            'reference' => $this->reference,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currencyCode,
            'message' => sprintf('Withdrawal %s is now: %s.', $this->reference, $this->status->label()),
            'reason' => $this->status === InstructorWithdrawalStatus::Rejected ? $this->reason : null,
            'url' => route('dashboard.instructor.withdrawals'),
        ];
    }
}
