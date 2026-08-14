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
     *
     * Deliberately EXCLUDES NotRequired: a free demo costs nothing, so
     * there is no covered cost to speak of. Use this only where the
     * question is genuinely "was money secured for this lesson?" — the
     * paid-vs-demo distinction in LessonFinancialDispositionService is
     * the canonical example. For "may this booking be delivered?", use
     * permitsDelivery() instead.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Paid, self::PackageFunded => true,
            default => false,
        };
    }

    /**
     * Whether this booking has satisfied its financial prerequisite for
     * DELIVERY — the single question the Lesson, Meeting and Earnings
     * lifecycles all ask before letting a confirmed booking proceed.
     *
     * Three states qualify, for three different reasons:
     *  - NotRequired  nothing was ever owed (free demo);
     *  - Paid         collected through the booking payment pipeline;
     *  - PackageFunded prepaid earlier through a package entitlement.
     * Pending/Failed/Refunded never qualify — delivery must not outrun
     * collection.
     *
     * This is the one owner of that concept. Domains must not restate it
     * as `in_array([Paid, NotRequired])` or grow their own
     * isPaidOrPackageFunded()-style helper: that duplication is exactly
     * what left package-funded bookings invisible to Lesson, Meeting and
     * Earnings after Phase 4D.
     *
     * Distinct from isSettled() on purpose. "May we deliver?" and "was
     * money secured?" give different answers for a free demo, and
     * collapsing them would either block demos or bill them.
     */
    public function permitsDelivery(): bool
    {
        return match ($this) {
            self::NotRequired, self::Paid, self::PackageFunded => true,
            default => false,
        };
    }

    /**
     * The same rule in the form a query builder can consume, so a
     * `whereIn` cannot drift from the predicate above.
     *
     * @return list<self>
     */
    public static function deliverable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status->permitsDelivery(),
        ));
    }

    /**
     * isSettled() in query form — the statuses whose cost has actually
     * been covered. Free demos are excluded, exactly as in the predicate.
     *
     * @return list<self>
     */
    public static function settled(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status->isSettled(),
        ));
    }
}
