<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Services\Mail\TransactionalMailSender;
use Illuminate\Notifications\Messages\MailMessage;

trait ConfiguresTransactionalEmail
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function viaQueues(): array
    {
        return ['mail' => 'notifications'];
    }

    /**
     * Applies the mailer, the category sender, and the shared SIRI branded
     * shell to a stock MailMessage.
     *
     * The view is set here rather than in each notification so that every
     * `->subject()/->line()/->action()` message renders inside one layout —
     * Laravel's unbranded default markdown theme is bypassed entirely. A
     * notification needing bespoke markup calls `->view(...)` *after* this
     * method; that later assignment overwrites the default, which is why
     * bespoke templates must use `->view()` and not `->markdown()`
     * (`markdown()` sets a different property and would lose the race).
     */
    protected function configureMailMessage(MailMessage $message): MailMessage
    {
        $mail = app(TransactionalMailSender::class);
        $sender = $mail->resolve($this->senderKey());

        return $message
            ->mailer($mail->mailer())
            ->from($sender['address'], $sender['name'])
            ->view([
                'html' => 'emails.notification',
                'text' => 'emails.notification-text',
            ]);
    }
}
