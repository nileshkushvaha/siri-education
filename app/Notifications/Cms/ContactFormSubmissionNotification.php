<?php

declare(strict_types=1);

namespace App\Notifications\Cms;

use App\Notifications\Admin\AdminAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class ContactFormSubmissionNotification extends AdminAlertNotification
{
    use Queueable;

    /**
     * @param array{
     *     block_id: string,
     *     page_id: string|null,
     *     page_slug: string|null,
     *     page_title: string|null,
     *     submitted_at: string,
     *     ip: string|null,
     *     user_agent: string|null,
     *     fields: array<string, mixed>,
     *     field_labels: array<string, string>
     * } $payload
     */
    public function __construct(
        private readonly array $payload
    ) {
        $this->onQueue('notifications')->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('New contact form submission')
            ->view('emails.cms.contact-form-submission', [
                'payload' => $this->payload,
            ]);
    }
}
