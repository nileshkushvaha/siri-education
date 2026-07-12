<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** Normalized provider attendance event shapes — anything else is rejected at parse time. */
enum MeetingAttendanceEventType: string
{
    /** A participant joined; leave may follow in a later event. */
    case Joined = 'joined';

    /** A participant left; carries the session's join time when the provider supplies it. */
    case Left = 'left';

    /** A complete join/leave session interval. */
    case Session = 'session';

    /** The provider reports an aggregated duration only (no interval). */
    case DurationOnly = 'duration_only';

    public function label(): string
    {
        return match ($this) {
            self::Joined => 'Joined',
            self::Left => 'Left',
            self::Session => 'Session',
            self::DurationOnly => 'Duration Only',
        };
    }
}
