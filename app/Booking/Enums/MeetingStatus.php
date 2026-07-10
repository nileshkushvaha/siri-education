<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** Per-booking meeting creation state, stored on bookings.meeting_status. */
enum MeetingStatus: string
{
    case Pending = 'pending';
    case Created = 'created';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Created => 'Created',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Created => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
