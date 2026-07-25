<?php

declare(strict_types=1);

namespace App\Alerts\Enums;

/** SRS §26.28 "Alert Severity". */
enum OperationalAlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Info => 'gray',
            self::Warning => 'warning',
            self::High => 'danger',
            self::Critical => 'danger',
        };
    }

    /** Only High/Critical alerts page anyone — Info/Warning are queue-visible only. */
    public function isAlertWorthy(): bool
    {
        return in_array($this, [self::High, self::Critical], strict: true);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    public function higherOf(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }
}
