<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Recording;
use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SRS §12.36 "Recording available, if enabled" — sent to both the
 * student and instructor once, via NotificationIdempotencyGuard
 * (RecordingService::notifyAvailable()). Never links to a public URL —
 * the action always routes back through the policy-rechecked download
 * route.
 */
final class RecordingAvailableNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail, Queueable;

    public function __construct(
        public readonly Recording $recording,
    ) {
        $this->onQueue('notifications');
    }

    public function emailCategory(): string
    {
        return 'booking';
    }

    public function senderKey(): string
    {
        return 'support';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('Your lesson recording is available')
            ->line('The recording for your lesson is now available in your dashboard.')
            ->action('View recording', route('dashboard.my-bookings'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Recording available',
            'message' => 'The recording for your lesson is now available.',
            'recording_id' => $this->recording->id,
            'booking_id' => $this->recording->booking_id,
        ];
    }
}
