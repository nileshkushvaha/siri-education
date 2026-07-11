<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Reservation lifecycle: reserved while the withdrawal is live,
 * released when it is rejected/cancelled, consumed (future execution
 * phase) once the money has actually left the platform.
 */
enum WithdrawalAllocationStatus: string
{
    case Reserved = 'reserved';
    case Released = 'released';
    case Consumed = 'consumed';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reserved',
            self::Released => 'Released',
            self::Consumed => 'Consumed',
        };
    }
}
