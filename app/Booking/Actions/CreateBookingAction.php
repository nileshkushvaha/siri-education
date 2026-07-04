<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Single-responsibility action: persists the booking record.
 * Does NOT validate availability, dispatch events, or audit —
 * that is BookingService's concern (mirrors RegisterUserAction).
 */
final class CreateBookingAction
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    /** @param array<string, mixed> $attributes service-computed extras (type id, payment snapshot, …) */
    public function execute(CreateBookingData $data, BookingStatus $status = BookingStatus::Pending, array $attributes = []): Booking
    {
        return DB::transaction(
            fn (): Booking => $this->bookings->create($data, $status, $attributes),
        );
    }
}
