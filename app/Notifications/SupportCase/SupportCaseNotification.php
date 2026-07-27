<?php

declare(strict_types=1);

namespace App\Notifications\SupportCase;

use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for support-case lifecycle notifications. Distinct from
 * the `App\Notifications\Support` namespace, which
 * belongs to the unrelated one-way public contact/support form
 * (ContactFormController) and is not touched by this module.
 */
abstract class SupportCaseNotification extends Notification implements ShouldQueue, TransactionalEmail
{
    use ConfiguresTransactionalEmail;

    public function emailCategory(): string
    {
        return 'support_case';
    }

    public function senderKey(): string
    {
        return 'support';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }
}
