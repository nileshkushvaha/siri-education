<?php

declare(strict_types=1);

namespace App\Notifications\Instructor;

use App\Enums\InstructorStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InstructorProfileStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly InstructorStatus $status,
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
            InstructorStatus::Approved => 'Your instructor profile has been approved',
            InstructorStatus::Rejected => 'Your instructor profile was not approved',
            InstructorStatus::Active => 'Your instructor profile is now live',
            default => 'Your instructor profile status has been updated',
        };

        $message = match ($this->status) {
            InstructorStatus::Approved => 'Great news! Your instructor profile has been reviewed and approved. It is now visible to the community.',
            InstructorStatus::Rejected => 'Thank you for submitting your instructor profile. Unfortunately it did not meet our current requirements. Please contact support for more details.',
            InstructorStatus::Active => 'Your instructor profile is now active and live. Students can find you on the instructors page.',
            default => 'Your instructor profile status has been updated to: '.$this->status->label(),
        };

        return (new MailMessage)
            ->subject($subject.' — '.config('app.name'))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($message)
            ->action('View Your Profile', route('instructors.show', $notifiable))
            ->line('Thank you for being part of '.config('app.name').'.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'status' => $this->status->value,
            'message' => 'Your instructor profile status is now: '.$this->status->label(),
            'url' => route('instructors.show', $notifiable),
        ];
    }
}
