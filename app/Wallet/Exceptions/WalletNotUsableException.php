<?php

declare(strict_types=1);

namespace App\Wallet\Exceptions;

final class WalletNotUsableException extends WalletException
{
    public static function forStatus(string $walletId, string $status): self
    {
        return new self(sprintf('Wallet %s is %s and cannot be used.', $walletId, $status));
    }
}
