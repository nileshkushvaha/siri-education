<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\InvalidStatusTransitionException;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

final class CancelBookingAction
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    /** @throws InvalidStatusTransitionException */
    public function execute(Booking $booking, CancelBookingData $data): Booking
    {
        if (! $booking->status->canTransitionTo(BookingStatus::Cancelled)) {
            throw InvalidStatusTransitionException::between($booking->status, BookingStatus::Cancelled);
        }

        return DB::transaction(
            fn (): Booking => $this->bookings->transitionStatus($booking, BookingStatus::Cancelled, [
                'cancelled_by' => $data->cancelledBy,
                'cancellation_reason' => $data->reason,
                'cancelled_at' => now(),
            ]),
        );
    }
}
