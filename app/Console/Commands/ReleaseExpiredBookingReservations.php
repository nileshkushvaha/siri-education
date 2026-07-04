<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Exceptions\BookingException;
use Illuminate\Console\Command;

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

        foreach ($bookings->expiredReservations() as $booking) {
            try {
                $service->cancel($booking, new CancelBookingData(
                    BookingActor::System,
                    'Payment was not completed within the reservation window.',
                ));
                $released++;
            } catch (BookingException $e) {
                $this->warn(sprintf('%s: %s', $booking->reference, $e->getMessage()));
            }
        }

        $this->info(sprintf('Released %d expired reservation(s).', $released));

        return self::SUCCESS;
    }
}
