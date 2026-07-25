<?php

declare(strict_types=1);

namespace App\Alerts\Enums;

/**
 * SRS §26.36 "Alert Status" — the phase brief's explicit three-state
 * lifecycle. No backward transition exists: a recurrence of the same
 * fingerprint after Resolved starts a fresh alert row (a new episode)
 * rather than reopening this one, mirroring
 * `SuspiciousActivityFlagStatus`'s active_fingerprint-clears-on-
 * terminal precedent — see `OperationalAlertService::createOrMerge()`.
 */
enum OperationalAlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'warning',
            self::Resolved => 'success',
        };
    }

    public function isActive(): bool
    {
        return $this !== self::Resolved;
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved;
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::Resolved],
            self::Acknowledged => [self::Resolved],
            self::Resolved => [],
        };
    }
}
