<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\InvalidStatusTransitionException;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Marks a booking Completed or NoShow — the two terminal outcomes
 * of a confirmed booking share one guard-and-transition shape.
 */
final class CompleteBookingAction
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    /** @throws InvalidStatusTransitionException */
    public function execute(Booking $booking, BookingStatus $outcome = BookingStatus::Completed): Booking
    {
        if (! in_array($outcome, [BookingStatus::Completed, BookingStatus::NoShow], strict: true)
            || ! $booking->status->canTransitionTo($outcome)) {
            throw InvalidStatusTransitionException::between($booking->status, $outcome);
        }

        return DB::transaction(
            fn (): Booking => $this->bookings->transitionStatus($booking, $outcome, [
                'completed_at' => $outcome === BookingStatus::Completed ? now() : null,
            ]),
        );
    }
}
