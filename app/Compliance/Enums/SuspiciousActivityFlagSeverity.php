<?php

declare(strict_types=1);

namespace App\Compliance\Enums;

enum SuspiciousActivityFlagSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'warning',
            self::High => 'danger',
            self::Critical => 'danger',
        };
    }

    /**
     * The phase brief's "configured warning/critical flags" alert
     * threshold maps to this enum's top two tiers — High is the
     * "warning" tier, Critical is itself.
     */
    public function isAlertWorthy(): bool
    {
        return in_array($this, [self::High, self::Critical], strict: true);
    }
}
