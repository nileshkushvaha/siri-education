<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** Whether a participant may join a booking's meeting right now, and why not when they can't. */
enum MeetingJoinAvailability: string
{
    case Available = 'available';
    case NotReady = 'not_ready';
    case TooEarly = 'too_early';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Join Class',
            self::NotReady => 'Meeting link unavailable',
            self::TooEarly => 'Available shortly before lesson starts',
            self::Unavailable => '',
        };
    }
}
