<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;

/**
 * Orchestration entry point for the booking lifecycle. The concrete
 * service composes: validation pipeline → action → domain event →
 * AuditTrailService. Notifications flow from the Activity Log
 * pipeline, never from this service (docs/decisions.md).
 */
interface BookingServiceInterface
{
    /**
     * Validate and persist a booking request. Auto-confirms when the
     * type does not require approval.
     *
     * @throws BookingException
     */
    public function request(CreateBookingData $data): Booking;

    /** @throws BookingException */
    public function confirm(Booking $booking): Booking;

    /** @throws BookingException */
    public function reschedule(Booking $booking, RescheduleBookingData $data): Booking;

    /** @throws BookingException */
    public function cancel(Booking $booking, CancelBookingData $data): Booking;

    /** @throws BookingException */
    public function complete(Booking $booking): Booking;

    /** @throws BookingException */
    public function markNoShow(Booking $booking): Booking;
}
