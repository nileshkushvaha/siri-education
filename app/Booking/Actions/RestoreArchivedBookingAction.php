<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\DTOs\BookingRestorationResult;
use App\Booking\Exceptions\BookingArchivalException;
use App\Models\Booking;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of booking restoration (Phase 17U.1). Restoration
 * means restoring administrative *visibility* of the preserved
 * record only — it clears `deleted_at` and nothing else. It never
 * reopens availability, recreates a reservation/meeting, reconfirms
 * payment, reverses a refund, recreates an earning, republishes a
 * review, reopens review eligibility, sends a notification, or
 * touches wallet/settlement state — the booking's status, payment
 * status, and every dependent record are simply whatever they already
 * were before archival.
 */
final class RestoreArchivedBookingAction
{
    private const string LOG_NAME = 'bookings';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /** @throws BookingArchivalException */
    public function execute(Booking $booking, User $admin, string $reason): BookingRestorationResult
    {
        return DB::transaction(function () use ($booking, $admin, $reason): BookingRestorationResult {
            $booking = Booking::withTrashed()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $booking->trashed()) {
                return new BookingRestorationResult($booking, applied: false); // idempotent repeat
            }

            if (trim($reason) === '') {
                throw new BookingArchivalException('A restoration reason is required.');
            }

            $booking->restore();

            $this->audit->logOverride(
                $admin,
                self::LOG_NAME,
                'booking_restored',
                sprintf('Booking %s restored from archive.', $booking->reference),
                $reason,
                $booking,
                ['booking_id' => $booking->id, 'status' => $booking->status->value],
            );

            return new BookingRestorationResult($booking, applied: true);
        });
    }
}
