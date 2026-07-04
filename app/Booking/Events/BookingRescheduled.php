<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class BookingRescheduled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
        public readonly CarbonImmutable $previousEndsAt,
    ) {}
}
