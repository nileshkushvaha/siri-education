<?php

declare(strict_types=1);

namespace App\Notifications\Wallet;

use App\Lessons\Enums\LessonOutcome;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the student when a lesson-outcome refund has been credited to
 * their wallet — an instructor no-show, a technical failure, or an admin
 * exception resolution.
 *
 * This path is distinct from a student-initiated cancellation, which is
 * already covered by BookingCancelledNotification's frozen refund line.
 * A lesson-outcome refund is executed later (often by an admin, often days
 * after the lesson), so without this the money simply appeared in the
 * student's wallet with no explanation.
 *
 * Content is deliberately limited to what the student already sees on their
 * own wallet page: amount, resulting balance, booking reference, and a
 * plain-language reason. Never the source payment id, provider reference,
 * disposition id, internal reason code, or admin audit notes.
 */
final class LessonRefundCreditedNotification extends WalletNotification
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly LessonFinancialDisposition $disposition,
        public readonly WalletLedgerEntry $entry,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reference = $this->disposition->booking?->reference;

        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('%s refunded to your wallet', $this->amountFormatted()))
            ->greeting('Your refund has been credited')
            ->line(sprintf(
                'We have credited %s back to your wallet%s.',
                $this->amountFormatted(),
                $reference !== null ? sprintf(' for booking %s', $reference) : '',
            ))
            ->line($this->reasonLine());

        if ($this->entry->balance_after_minor !== null) {
            $mail->line(sprintf('Your wallet balance is now %s.', MoneyFormatter::format(
                $this->entry->balance_after_minor,
                $this->entry->currency_code,
            )));
        }

        return $mail
            ->action('View my wallet', route('dashboard.wallet'))
            ->line('The credit is available immediately and can be used towards any future booking.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => sprintf('%s refunded to your wallet', $this->amountFormatted()),
            'message' => $this->reasonLine(),
            'booking_reference' => $this->disposition->booking?->reference,
        ];
    }

    /** Plain-language cause — never the internal reason code. */
    private function reasonLine(): string
    {
        return match ($this->disposition->outcome) {
            LessonOutcome::InstructorNoShow => 'Your instructor was unable to attend this lesson, so it has been refunded in full.',
            LessonOutcome::TechnicalIssue => 'This lesson could not go ahead because of a technical problem, so it has been refunded in full.',
            LessonOutcome::BothAbsent => 'This lesson did not take place, so it has been refunded in full.',
            LessonOutcome::Cancelled => 'This lesson was cancelled, so it has been refunded in full.',
            default => 'This lesson has been reviewed and refunded in full.',
        };
    }

    private function amountFormatted(): string
    {
        return MoneyFormatter::format($this->entry->amount_minor, $this->entry->currency_code);
    }
}
