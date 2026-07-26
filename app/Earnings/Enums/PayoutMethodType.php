<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Payout destination kinds. Only bank transfer exists today; adding a
 * type = new case + enabling it in enabledForInstructors(). The
 * withdrawal workflow is type-agnostic (it consumes the encrypted
 * snapshot), so new types never rewrite it.
 */
enum PayoutMethodType: string
{
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank Transfer',
        };
    }

    /** Types instructors may create through self-service UI. */
    public static function enabledForInstructors(): array
    {
        return [self::BankTransfer];
    }
}
