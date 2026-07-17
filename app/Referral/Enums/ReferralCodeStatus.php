<?php

declare(strict_types=1);

namespace App\Referral\Enums;

enum ReferralCodeStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
        };
    }
}
