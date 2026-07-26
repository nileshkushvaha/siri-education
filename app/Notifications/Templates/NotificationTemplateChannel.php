<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

/**
 * GAP-039 — the only two channels the template system covers. SMS/
 * WhatsApp already exist as delivery channels elsewhere (see
 * SmsChannel/WhatsAppChannel) but have no live provider yet and reuse
 * plainText(), which stays code-owned — not part of this phase.
 */
enum NotificationTemplateChannel: string
{
    case Mail = 'mail';
    case Database = 'database';
}
