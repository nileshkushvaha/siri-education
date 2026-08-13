<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\Contracts\BookingAcademicContextRepositoryInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Single-responsibility action: persists the booking record.
 * Does NOT validate availability, dispatch events, or audit —
 * that is BookingService's concern (mirrors RegisterUserAction).
 *
 * Phase 3: when $data carries a resolved academic snapshot
 * (country-aware Free Demo only), the BookingAcademicContext row is
 * created here too, inside the SAME transaction — a confirmed Booking
 * must never exist without its required snapshot, and a snapshot must
 * never survive a rolled-back Booking. Deliberately not done via a
 * queued listener (§22 forbids asynchronous creation of this critical
 * historical record).
 */
final class CreateBookingAction
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
        private readonly BookingAcademicContextRepositoryInterface $academicContexts,
    ) {}

    /** @param array<string, mixed> $attributes service-computed extras (type id, payment snapshot, …) */
    public function execute(CreateBookingData $data, BookingStatus $status = BookingStatus::Pending, array $attributes = []): Booking
    {
        return DB::transaction(function () use ($data, $status, $attributes): Booking {
            $booking = $this->bookings->create($data, $status, $attributes);

            if ($data->academicContext !== null) {
                $this->academicContexts->createFor($booking, $data->academicContext);
            }

            return $booking;
        });
    }
}
