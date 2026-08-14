<?php

declare(strict_types=1);

namespace App\Booking\Enums;

enum BookingPaymentStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /**
     * Phase 4D — this booking is funded by a package the student has
     * ALREADY paid for (StudentPackagePurchase), so nothing is
     * collectable at booking time and nothing is owed.
     *
     * Deliberately its own case rather than a reuse of NotRequired or a
     * zero-value Paid:
     *  - NotRequired means "this booking type costs nothing" and reads
     *    as FREE everywhere it is displayed — a package lesson is
     *    prepaid, not free (spec §30);
     *  - a fake zero-price captured payment would corrupt revenue
     *    reporting, which reads captured amounts as real collection.
     * The booking keeps its real `price`/`currency` so reporting still
     * sees the lesson's commercial value; only the collection
     * expectation differs. Not payable, so no payment can be initiated
     * against it, and never reservation-expired (there is no hold to
     * lapse — see BookingRepository::expiredReservations()).
     */
    case PackageFunded = 'package_funded';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Not Required',
            self::Pending => 'Payment Pending',
            self::Paid => 'Paid',
            self::Failed => 'Payment Failed',
            self::Refunded => 'Refunded',
            self::PackageFunded => 'Covered by Package',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotRequired => 'gray',
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Refunded => 'danger',
            self::PackageFunded => 'info',
        };
    }

    /** Payment can still be attempted (initiate/retry). */
    public function isPayable(): bool
    {
        return match ($this) {
            self::Pending, self::Failed => true,
            default => false,
        };
    }

    /**
     * Whether the lesson's cost is already covered — either collected
     * normally or prepaid through a package. The question
     * "may this booking proceed financially?" must use this rather than
     * `=== Paid`, so package-funded bookings are not mistaken for unpaid.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Paid, self::PackageFunded => true,
            default => false,
        };
    }
}
