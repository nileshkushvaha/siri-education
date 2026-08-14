<?php

declare(strict_types=1);

namespace App\Package\Enums;

/**
 * Phase 4D — lifecycle of one entitlement unit committed to one future
 * Booking.
 *
 * Reserved is the only non-terminal state, and both exits are final:
 *
 *      Reserved ──► Consumed   the lesson was delivered and a
 *                              consumption ledger row was written
 *      Reserved ──► Released   the booking will never consume
 *                              (cancelled, non-consuming outcome, or
 *                              the entitlement expired first)
 *
 * A reservation never returns to Reserved. Re-booking after a release
 * produces a NEW reservation against the new booking, so the ledger
 * reads as a history of decisions rather than a mutable counter.
 *
 * Only Reserved consumes capacity: availableToBook() counts exactly
 * this state, which is why releasing genuinely returns the unit and
 * consuming does not double-count it against `used_quantity`.
 */
enum PackageEntitlementReservationStatus: string
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

    public function color(): string
    {
        return match ($this) {
            self::Reserved => 'warning',
            self::Released => 'gray',
            self::Consumed => 'success',
        };
    }

    /** Whether this reservation is currently holding a unit of capacity. */
    public function holdsCapacity(): bool
    {
        return $this === self::Reserved;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Reserved;
    }
}
