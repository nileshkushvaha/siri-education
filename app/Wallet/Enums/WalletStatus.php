<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

enum WalletStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Frozen => 'Frozen',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Frozen => 'warning',
            self::Closed => 'gray',
        };
    }

    /** A frozen or closed wallet can never be debited or hold-placed. */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
