<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Compensation-agreement lifecycle. Financial terms freeze at
 * activation — a rate change is always a replacement agreement, never
 * an edit. Single source of truth for the state machine;
 * InstructorCompensationAgreementService guards every write.
 */
enum CompensationAgreementStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Active => 'Active',
            self::Ended => 'Ended',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'info',
            self::Active => 'success',
            self::Ended => 'warning',
            self::Cancelled => 'danger',
        };
    }

    /** Financial terms (amount, currency, basis, overrides) editable? */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Scheduled => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ended, self::Cancelled => true,
            default => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Active, self::Cancelled],
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Ended],
            self::Ended, self::Cancelled => [],
        };
    }
}
