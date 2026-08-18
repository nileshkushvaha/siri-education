<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * The recording lifecycle. A recording existing at the meeting
 * provider does NOT mean SIRI holds it durably — hence the two
 * intermediate states between "the provider has it" and "we can serve
 * it":
 *
 *   Pending      registered as eligible; nothing fetched yet
 *        ↓       (claimed by exactly one worker)
 *   Transferring downloading from the provider / uploading to storage
 *        ↓       (locator persisted — bytes may exist remotely now)
 *   Stored       upload returned success, integrity NOT yet verified
 *        ↓
 *   Available    verified against the storage backend; serveable
 *        ↓
 *   Expired      retention elapsed: object deleted, metadata retained
 *
 * Transferring falls back to Pending on a transient failure (the sweep
 * retries it, and also reclaims rows abandoned by a crashed worker).
 * Failed is terminal for the automatic pipeline; only an audited admin
 * retry returns it to Pending.
 *
 * Stored exists specifically so a crash between "upload finished" and
 * "verification finished" is recoverable: the retry re-verifies the
 * already-persisted locator instead of uploading a second copy.
 */
enum RecordingStatus: string
{
    case Pending = 'pending';
    case Transferring = 'transferring';
    case Stored = 'stored';
    case Available = 'available';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Transferring => 'Transferring',
            self::Stored => 'Stored',
            self::Available => 'Available',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Transferring, self::Stored => 'info',
            self::Available => 'success',
            self::Failed => 'danger',
            self::Expired => 'gray',
        };
    }

    /** The pipeline is finished with this row — no sweep will pick it up again. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Available, self::Failed, self::Expired => true,
            default => false,
        };
    }

    /** A storage object exists (or may exist) for this row. */
    public function hasStoredObject(): bool
    {
        return match ($this) {
            self::Stored, self::Available => true,
            default => false,
        };
    }
}
