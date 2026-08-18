<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Enums;

/**
 * The lifecycle of one AI-assisted quality insight.
 *
 *   Pending   requested; the queued run has not returned yet
 *   Ready     validated AI output stored, awaiting an administrator
 *   Reviewed  an administrator has read it and taken responsibility
 *   Failed    the run did not produce usable output (reason recorded)
 *
 * Reviewed is the point of the whole feature: an insight is a prompt
 * for human attention, and this status records that a person actually
 * looked. Nothing in the platform reads these rows to make a decision —
 * no ranking, no alert, no status change follows from them.
 */
enum QualityInsightStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Reviewed = 'reviewed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Generating',
            self::Ready => 'Awaiting review',
            self::Reviewed => 'Reviewed',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Ready => 'warning',
            self::Reviewed => 'success',
            self::Failed => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
