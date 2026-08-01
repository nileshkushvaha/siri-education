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

    protected function configureMailMessage(MailMessage $message): MailMessage
    {
        $mail = app(TransactionalMailSender::class);
        $sender = $mail->resolve($this->senderKey());

        return $message
            ->mailer($mail->mailer())
            ->from($sender['address'], $sender['name']);
    }
}
