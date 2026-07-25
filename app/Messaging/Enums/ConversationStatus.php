<?php

declare(strict_types=1);

namespace App\Messaging\Enums;

/**
 * `Restricted` is a system-derived reflection of an active
 * MessagingRestriction on either participant (SRS §17.29 "Messaging
 * may be disabled when ... instructor is suspended ... admin disables
 * communication") — never set directly by a user action. `Closed` is
 * an explicit close by a participant or admin. Only
 * MessagingService may write this column.
 */
enum ConversationStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Closed => 'Closed',
            self::Restricted => 'Restricted',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Closed => 'gray',
            self::Restricted => 'danger',
        };
    }

    public function acceptsNewMessages(): bool
    {
        return $this === self::Active;
    }
}
