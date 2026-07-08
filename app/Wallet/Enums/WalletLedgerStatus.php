<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

enum WalletLedgerStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Posted => 'success',
            self::Reversed => 'gray',
            self::Failed => 'danger',
        };
    }
}
