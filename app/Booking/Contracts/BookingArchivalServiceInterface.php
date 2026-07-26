<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\BookingArchivalResult;
use App\Booking\DTOs\BookingRestorationResult;
use App\Booking\Exceptions\BookingArchivalException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Single entry point for administratively archiving/restoring a
 * booking. Archiving always soft-deletes; nothing here — or anywhere reachable from
 * it — ever physically deletes a booking or any dependent record.
 */
interface BookingArchivalServiceInterface
{
    /**
     * @throws AuthorizationException
     * @throws BookingArchivalException
     */
    public function archive(Booking $booking, User $admin, string $reason): BookingArchivalResult;

    /**
     * @throws AuthorizationException
     * @throws BookingArchivalException
     */
    public function restore(Booking $booking, User $admin, string $reason): BookingRestorationResult;
}
