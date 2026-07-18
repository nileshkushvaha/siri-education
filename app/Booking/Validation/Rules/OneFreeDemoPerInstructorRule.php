<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\FreeDemoAlreadyUsedException;
use App\Booking\Types\FreeDemoType;

/**
 * SRS 11.13 ("Student must not have already used a free demo with the
 * same instructor") / 11.39 ("A student may book one free demo per
 * instructor"). Registered on FreeDemoType::rules() only; the key()
 * guard is defense-in-depth in case this rule is ever resolved for a
 * different type by mistake — it must never affect paid bookings.
 *
 * Fast-fail copy, run before the host lock is acquired. Re-checked
 * again inside BookingService::request()'s transaction under
 * withInstructorLock(), exactly like DuplicateBookingRule, because a
 * second concurrent request could otherwise pass this check before
 * either has committed.
 */
final class OneFreeDemoPerInstructorRule implements BookingRuleInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($type->key() !== FreeDemoType::KEY) {
            return;
        }

        if ($this->bookings->freeDemoConsumed($data)) {
            throw FreeDemoAlreadyUsedException::make();
        }
    }
}
