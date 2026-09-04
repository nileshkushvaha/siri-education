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

        $this->assertWithinWindow($data->startsAt, isDemo: ! $type->isPaid());
    }

    /**
     * Shared with reschedule, which has no CreateBookingData.
     *
     * @param  bool  $isDemo  free (unpaid) bookings use the shorter demo notice
     */
    public function assertWithinWindow(CarbonImmutable $startsAt, bool $isDemo = false): void
    {
        $notice = $this->noticeMinutes($isDemo);

        if ($startsAt->lessThan(now()->addMinutes($notice))) {
            throw new BookingException(sprintf(
                $isDemo ? 'Demo bookings require at least %d minutes notice.' : 'Bookings require at least %d minutes notice.',
                $notice,
            ));
        }

        if ($startsAt->greaterThan(now()->addDays($this->settings->maximum_advance_booking_days))) {
            throw new BookingException(sprintf(
                'Bookings cannot be made more than %d days in advance.',
                $this->settings->maximum_advance_booking_days,
            ));
        }
    }

    public function isWithinWindow(CarbonImmutable $startsAt, bool $isDemo = false): bool
    {
        try {
            $this->assertWithinWindow($startsAt, $isDemo);

            return true;
        } catch (BookingException) {
            return false;
        }
    }

    public function noticeMinutes(bool $isDemo): int
    {
        return $isDemo
            ? $this->settings->demo_minimum_booking_notice_minutes
            : $this->settings->minimum_booking_notice_minutes;
    }
}
