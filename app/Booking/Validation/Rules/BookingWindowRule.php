<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;

/**
 * The slot must sit inside the bookable window: enough lead time,
 * not too far ahead, and a positive duration. Limits are admin-tunable
 * via BookingSettings.
 */
final class BookingWindowRule implements BookingRuleInterface
{
    public function __construct(
        private readonly BookingSettings $settings,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($data->durationMinutes < 1) {
            throw new BookingException('Booking duration must be at least one minute.');
        }

        $this->assertWithinWindow($data->startsAt);
    }

    /** Shared with reschedule, which has no CreateBookingData. */
    public function assertWithinWindow(CarbonImmutable $startsAt): void
    {
        if ($startsAt->lessThan(now()->addMinutes($this->settings->minimum_booking_notice_minutes))) {
            throw new BookingException(sprintf(
                'Bookings require at least %d minutes notice.',
                $this->settings->minimum_booking_notice_minutes,
            ));
        }

        if ($startsAt->greaterThan(now()->addDays($this->settings->maximum_advance_booking_days))) {
            throw new BookingException(sprintf(
                'Bookings cannot be made more than %d days in advance.',
                $this->settings->maximum_advance_booking_days,
            ));
        }
    }

    public function isWithinWindow(CarbonImmutable $startsAt): bool
    {
        try {
            $this->assertWithinWindow($startsAt);

            return true;
        } catch (BookingException) {
            return false;
        }
    }
}
