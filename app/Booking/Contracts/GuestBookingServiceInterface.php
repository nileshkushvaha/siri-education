<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\GuestBookingData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Public (unauthenticated) booking flow. Guests never see or pick
 * teachers — availability is aggregated across eligible teachers and
 * the assignment engine chooses on booking. Post-creation management
 * is authorized solely by the booking's manage_token.
 */
interface GuestBookingServiceInterface
{
    /** @return Collection<int, string> dates (Y-m-d, in $timezone) with at least one open slot */
    public function availableDates(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone = 'UTC',
        ?int $teacherId = null,
    ): Collection;

    /** @return Collection<int, TimeSlotData> deduplicated across teachers, in $timezone */
    public function availableSlots(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $date,
        string $timezone = 'UTC',
        ?int $teacherId = null,
    ): Collection;

    /** @throws BookingException */
    public function book(GuestBookingData $data): Booking;

    /**
     * Resolve a guest booking by reference + manage token.
     * Fails as "not found" — never reveals whether the reference exists.
     */
    public function findForGuest(string $reference, string $token): Booking;

    /** @throws BookingException */
    public function cancel(Booking $booking, ?string $reason = null): Booking;

    /** @throws BookingException */
    public function reschedule(Booking $booking, CarbonImmutable $startsAt, ?string $reason = null): Booking;
}
