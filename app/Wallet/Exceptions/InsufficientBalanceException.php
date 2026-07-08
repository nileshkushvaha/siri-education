<?php

declare(strict_types=1);

namespace App\Wallet\Exceptions;

final class InsufficientBalanceException extends WalletException
{
    public static function forDebit(string $walletId, int $requestedMinor, int $availableMinor): self
    {
        return new self(sprintf(
            'Wallet %s has insufficient available balance: requested %d, available %d.',
            $walletId,
            $requestedMinor,
            $availableMinor,
        ));
    }
}
