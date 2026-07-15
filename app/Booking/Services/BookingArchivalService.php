<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Actions\ArchiveBookingAction;
use App\Booking\Actions\RestoreArchivedBookingAction;
use App\Booking\Contracts\BookingArchivalServiceInterface;
use App\Booking\DTOs\BookingArchivalResult;
use App\Booking\DTOs\BookingRestorationResult;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class BookingArchivalService implements BookingArchivalServiceInterface
{
    public function __construct(
        private readonly ArchiveBookingAction $archiveAction,
        private readonly RestoreArchivedBookingAction $restoreAction,
    ) {}

    public function archive(Booking $booking, User $admin, string $reason): BookingArchivalResult
    {
        if (! $admin->can('archive', $booking)) {
            throw new AuthorizationException('You may not archive this booking.');
        }

        return $this->archiveAction->execute($booking, $admin, $reason);
    }

    public function restore(Booking $booking, User $admin, string $reason): BookingRestorationResult
    {
        if (! $admin->can('restore', $booking)) {
            throw new AuthorizationException('You may not restore this booking.');
        }

        return $this->restoreAction->execute($booking, $admin, $reason);
    }
}
