<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Models\Booking;

/** applied=false means an idempotent repeat — the booking was already archived, nothing changed. */
final readonly class BookingArchivalResult
{
    public function __construct(
        public Booking $booking,
        public bool $applied,
    ) {}
}
