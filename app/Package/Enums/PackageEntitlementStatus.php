<?php

declare(strict_types=1);

namespace App\Package\Enums;

/**
 * Lifecycle of a StudentPackageEntitlement — the consumed-value side of
 * the package domain, distinct from InstructorPackageProposalStatus
 * (the commercial negotiation side). An entitlement is created Active
 * at acceptance and only ever leaves Active; it is never re-opened.
 *
 * Completed is reached automatically when the last lesson is consumed
 * (PackageEntitlementService::consumeLesson()); Cancelled/Expired are
 * administrative end states reserved for later phases — nothing in
 * Phase 4A transitions into them yet.
 */
enum PackageEntitlementStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Completed => 'info',
            self::Cancelled => 'gray',
            self::Expired => 'danger',
        };
    }

    /** Only an Active entitlement may have a lesson consumed against it. */
    public function isConsumable(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }
}
