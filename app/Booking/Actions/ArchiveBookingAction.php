<?php

declare(strict_types=1);

namespace App\Booking\Actions;

use App\Booking\DTOs\BookingArchivalResult;
use App\Booking\Exceptions\BookingArchivalException;
use App\Models\Booking;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of booking archival. Lock → verify terminal →
 * verify not already archived → require a reason →
 * soft-delete → audit, all in one transaction. Never called directly
 * from a Filament callback — BookingArchivalService is the boundary.
 * Archiving changes only `deleted_at`; status, payment status, refund
 * state, lesson outcome, meeting state, review status, earning
 * status, and settlement state are all left exactly as they were.
 */
final class ArchiveBookingAction
{
    private const string LOG_NAME = 'bookings';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /** @throws BookingArchivalException */
    public function execute(Booking $booking, User $admin, string $reason): BookingArchivalResult
    {
        return DB::transaction(function () use ($booking, $admin, $reason): BookingArchivalResult {
            $booking = Booking::withTrashed()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($booking->trashed()) {
                return new BookingArchivalResult($booking, applied: false); // idempotent repeat
            }

            if (! $booking->status->isTerminal()) {
                throw new BookingArchivalException(sprintf(
                    'Booking %s cannot be archived — it has not reached a terminal status.',
                    $booking->reference,
                ));
            }

            if (trim($reason) === '') {
                throw new BookingArchivalException('An archival reason is required.');
            }

            $booking->delete();

            $this->audit->logOverride(
                $admin,
                self::LOG_NAME,
                'booking_archived',
                sprintf('Booking %s archived.', $booking->reference),
                $reason,
                $booking,
                ['booking_id' => $booking->id, 'status' => $booking->status->value],
            );

            return new BookingArchivalResult($booking, applied: true);
        });
    }
}
