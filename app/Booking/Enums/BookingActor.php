<?php

declare(strict_types=1);

namespace App\Booking\Enums;

use App\Models\Booking;
use App\Models\User;

/**
 * Who performed a lifecycle mutation (cancel, reschedule).
 * Mirrors the Activity Log actor-type concept (User, Guest, System)
 * but scoped to booking participants.
 */
enum BookingActor: string
{
    case Attendee = 'attendee';
    case Host = 'host';
    case Admin = 'admin';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Attendee => 'Attendee',
            self::Host => 'Host',
            self::Admin => 'Admin',
            self::System => 'System',
        };
    }

    /** Who a user is relative to a booking; null user = System. */
    public static function forUser(?User $user, Booking $booking): self
    {
        if ($user === null) {
            return self::System;
        }

        return match ($user->id) {
            $booking->host_id => self::Host,
            $booking->attendee_id => self::Attendee,
            default => self::Admin,
        };
    }
}
