<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

enum WalletLedgerDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
        };
    }

    public function opposite(): self
    {
        return $this === self::Credit ? self::Debit : self::Credit;
    }
}
