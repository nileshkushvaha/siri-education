<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Enums;

/**
 * The review lifecycle of one finding.
 *
 * There is no "actioned" or "enforced" state, because no enforcement
 * follows from a finding. An administrator reads it and either agrees
 * it warrants attention (Confirmed) or does not (Dismissed); any
 * consequence for a user happens through the existing account and
 * messaging-restriction paths, by an explicit human decision recorded
 * there.
 */
enum MessageSafetyFindingStatus: string
{
    case Open = 'open';
    case Confirmed = 'confirmed';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Awaiting review',
            self::Confirmed => 'Confirmed',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Confirmed => 'danger',
            self::Dismissed => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Open;
    }
}
