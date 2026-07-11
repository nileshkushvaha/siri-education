<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Payout method lifecycle. Sensitive details are editable only while
 * draft or rejected; verified details are immutable — changing a bank
 * account means creating a replacement method. Single source of truth
 * for the state machine; InstructorPayoutMethodService guards every
 * write.
 */
enum PayoutMethodStatus: string
{
    case Draft = 'draft';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingVerification => 'Pending Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingVerification => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::Disabled => 'gray',
        };
    }

    /** Sensitive details may be (re-)entered only in these states. */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Rejected => true,
            default => false,
        };
    }

    /** Counts against duplicate-fingerprint checks and default selection. */
    public function isActive(): bool
    {
        return $this !== self::Disabled;
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingVerification, self::Disabled],
            self::PendingVerification => [self::Verified, self::Rejected, self::Draft, self::Disabled],
            // Verified details are immutable — the only exit is disable.
            self::Verified => [self::Disabled],
            // Rejected methods are corrected (new details) and resubmitted.
            self::Rejected => [self::PendingVerification, self::Disabled],
            self::Disabled => [],
        };
    }
}
