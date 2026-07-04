<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\InvalidStatusTransitionException;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

final class ConfirmBookingAction
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    /** @throws InvalidStatusTransitionException */
    public function execute(Booking $booking): Booking
    {
        if (! $booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            throw InvalidStatusTransitionException::between($booking->status, BookingStatus::Confirmed);
        }

        return DB::transaction(
            fn (): Booking => $this->bookings->transitionStatus($booking, BookingStatus::Confirmed, [
                'confirmed_at' => now(),
            ]),
        );
    }
}
