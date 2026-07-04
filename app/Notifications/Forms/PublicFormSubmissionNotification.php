<?php

declare(strict_types=1);

namespace App\Notifications\Forms;

use App\Forms\Enums\PublicFormType;
use App\Models\PublicFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PublicFormSubmissionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PublicFormType $type,
        private readonly PublicFormSubmission $submission,
    ) {
        $this->onQueue('notifications')->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->type->emailSubject())
            ->view('emails.forms.public-form-submission', [
                'type' => $this->type,
                'submission' => $this->submission,
            ]);
    }
}
