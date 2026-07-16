<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Exceptions\BookingException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Releases paid-booking reservations whose payment hold has lapsed —
 * the slot reopens and the usual cancellation events (notifications,
 * audit, refund sync) fire through the engine.
 */
class ReleaseExpiredBookingReservations extends Command
{
    protected $signature = 'booking:release-expired';

    protected $description = 'Cancel unpaid booking reservations whose payment hold has lapsed';

    public function handle(BookingRepositoryInterface $bookings, BookingServiceInterface $service): int
    {
        $released = 0;

        foreach ($bookings->expiredReservations() as $candidate) {
            try {
                $wasReleased = DB::transaction(function () use ($bookings, $service, $candidate): bool {
                    // expiredReservations() queried its candidate list before
                    // this loop began — a booking here may already have been
                    // paid, cancelled, or rescheduled by a concurrent request.
                    // Re-fetch and lock before acting on it: cancelling the
                    // stale in-memory copy would lost-update a row another
                    // process already moved on from.
                    $fresh = $bookings->lockIfStillExpiredReservation($candidate->id);

                    if ($fresh === null) {
                        return false;
                    }

                    $service->cancel($fresh, new CancelBookingData(
                        BookingActor::System,
                        'Payment was not completed within the reservation window.',
                        expired: true,
                    ));

                    return true;
                });

                if ($wasReleased) {
                    $released++;
                }
            } catch (BookingException $e) {
                $this->warn(sprintf('%s: %s', $candidate->reference, $e->getMessage()));
            }
        }

        $this->info(sprintf('Released %d expired reservation(s).', $released));

        return self::SUCCESS;
    }
}
