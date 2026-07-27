<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

/**
 * The only two channels the template system covers. SMS/
 * WhatsApp already exist as delivery channels elsewhere (see
 * SmsChannel/WhatsAppChannel) but have no live provider yet and reuse
 * plainText(), which stays code-owned — not part of the template system.
 */
enum NotificationTemplateChannel: string
{
    case Mail = 'mail';
    case Database = 'database';
}
