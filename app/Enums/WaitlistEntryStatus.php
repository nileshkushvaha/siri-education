<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SRS §6.19/§10.28: waitlists are instructor-specific and booking
 * stays first-come-first-served — there is no exclusive, time-limited
 * offer to expire, so no `Notified`/`Expired` state exists here.
 * "Notified" is tracked as a plain `notified_at` timestamp on the
 * entry instead (an entry can be notified more than once, across
 * separate openings, without changing its status).
 */
enum WaitlistEntryStatus: string
{
    case Waiting = 'waiting';
    case Fulfilled = 'fulfilled';
    case Withdrawn = 'withdrawn';
    case Ineligible = 'ineligible';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Fulfilled => 'Fulfilled',
            self::Withdrawn => 'Withdrawn',
            self::Ineligible => 'Ineligible',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Waiting => 'info',
            self::Fulfilled => 'success',
            self::Withdrawn => 'gray',
            self::Ineligible => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
