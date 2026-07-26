<?php

declare(strict_types=1);

namespace App\Booking\Enums;

use App\Models\Booking;
use App\Models\User;

/**
 * Who performed a lifecycle mutation (cancel, reschedule). Mirrors the
 * Activity Log actor-type concept (User, Guest, System) but scoped to
 * booking participants — every booking participant is an authenticated
 * platform user (no unauthenticated guest participant concept exists
 * anywhere in the Booking domain).
 */
enum BookingActor: string
{
    case Student = 'student';
    case Instructor = 'instructor';
    case Admin = 'admin';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Instructor => 'Instructor',
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
            $booking->instructor_id => self::Instructor,
            $booking->student_id => self::Student,
            default => self::Admin,
        };
    }
}
