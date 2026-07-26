<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Pending (requested at meeting-creation time, awaiting provider
 * capture) -> Available (imported into private storage) or
 * Failed (permanently, after exhausting retries) -> Expired (retention
 * elapsed, media deleted, this row kept for historical evidence).
 */
enum RecordingStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Available => 'Available',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Available => 'success',
            self::Failed => 'danger',
            self::Expired => 'gray',
        };
    }
}
