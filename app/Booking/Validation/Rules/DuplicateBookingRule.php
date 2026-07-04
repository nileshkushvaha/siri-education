<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\DuplicateBookingException;

/**
 * Fast-fail duplicate check. Re-checked under the host lock in
 * BookingService — this copy just rejects early without locking.
 */
final class DuplicateBookingRule implements BookingRuleInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($this->bookings->duplicateExists($data)) {
            throw DuplicateBookingException::for($data);
        }
    }
}
