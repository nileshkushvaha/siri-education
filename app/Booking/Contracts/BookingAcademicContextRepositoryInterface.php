<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Models\Booking;
use App\Models\BookingAcademicContext;

interface BookingAcademicContextRepositoryInterface
{
    /**
     * Idempotent: a retried call for the same Booking must never create
     * a second snapshot row (Phase 3 §21/§39 "duplicate creation").
     */
    public function createFor(Booking $booking, BookingAcademicContextData $context): BookingAcademicContext;
}
