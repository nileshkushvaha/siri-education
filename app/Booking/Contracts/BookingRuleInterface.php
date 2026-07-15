<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;

/**
 * A single domain-validation rule (lead time, overlap, …). Rules are
 * resolved from the container, so they may inject repositories or
 * settings.
 */
interface BookingRuleInterface
{
    /** @throws BookingException when the rule is violated */
    public function check(CreateBookingData $data, BookingTypeInterface $type): void;
}
