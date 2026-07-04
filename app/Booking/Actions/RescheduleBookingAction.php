<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Exceptions\InvalidStatusTransitionException;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

final class RescheduleBookingAction
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    /**
     * Rescheduling keeps the current status — only terminal bookings
     * cannot move. Availability is checked by BookingService before
     * this action runs.
     *
     * @throws InvalidStatusTransitionException
     */
    public function execute(Booking $booking, RescheduleBookingData $data): Booking
    {
        if ($booking->status->isTerminal()) {
            throw InvalidStatusTransitionException::between($booking->status, $booking->status);
        }

        $duration = $data->durationMinutes
            ?? (int) $booking->starts_at->diffInMinutes($booking->ends_at);

        return DB::transaction(
            fn (): Booking => $this->bookings->reschedule(
                $booking,
                $data->startsAt,
                $data->startsAt->addMinutes($duration),
            ),
        );
    }
}
