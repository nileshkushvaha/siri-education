<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Enums;

/**
 * How much attention a finding deserves. Deliberately three coarse
 * bands rather than a numeric score: a number invites sorting, ranking
 * and thresholds, and this is an advisory signal about one message, not
 * a measurement of a person.
 */
enum MessageSafetyRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }

    /** Only medium and above count toward the compliance escalation rule. */
    public function isEscalatable(): bool
    {
        return $this !== self::Low;
    }
}
