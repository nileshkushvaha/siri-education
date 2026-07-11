<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

enum SettlementBatchStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'info',
            self::Processing => 'warning',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Cancelled => true,
            default => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /**
     * Processing/Failed exist for the future external-payout phase;
     * this phase wires draft → approved → paid plus safe cancellation
     * of draft/failed batches.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Cancelled],
            self::Approved => [self::Processing, self::Paid],
            self::Processing => [self::Paid, self::Failed],
            self::Failed => [self::Processing, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }
}
