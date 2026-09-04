<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * What a STUDENT is told about their lesson's recording — a
 * presentation of the ingestion lifecycle, deliberately coarser than
 * RecordingStatus and free of any provider or storage vocabulary.
 *
 *   Hidden       nothing is shown: playback is off, the lesson has not
 *                ended, or no recording was ever registered for it
 *   Processing   the lesson ended and its recording is still on its
 *                way (pending, transferring, or awaiting verification)
 *   Available    the student may watch it now
 *   Unavailable  a recording was expected but cannot be offered —
 *                capture failed permanently, or access was withheld
 *   Expired      it was available and the retention window has passed
 *
 * The distinction between Unavailable and Expired is kept because they
 * call for different words: one is "something went wrong", the other
 * is "this was always going to happen on this date".
 */
enum RecordingPlaybackState: string
{
    case Hidden = 'hidden';
    case Processing = 'processing';
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Expired = 'expired';

    public function isVisible(): bool
    {
        return $this !== self::Hidden;
    }

    public function label(): string
    {
        return match ($this) {
            self::Hidden => '',
            self::Processing => 'Recording processing',
            self::Available => 'Recording available',
            self::Unavailable => 'Recording unavailable',
            self::Expired => 'Recording no longer available',
        };
    }

    /** Student-facing explanation. Never names a provider, a backend, or a failure code. */
    public function description(): string
    {
        return match ($this) {
            self::Hidden => '',
            self::Processing => 'Your lesson recording is being prepared. It usually appears within an hour of the lesson ending.',
            self::Available => 'Watch this lesson again from your account.',
            self::Unavailable => 'A recording of this lesson is not available. Contact support if you believe this is an error.',
            self::Expired => 'Recordings are kept for a limited time after the lesson, and this one has now been removed.',
        };
    }

    /** Matches the x-ui.badge palette. */
    public function color(): string
    {
        return match ($this) {
            self::Hidden => 'slate',
            self::Processing => 'warning',
            self::Available => 'success',
            self::Unavailable => 'danger',
            self::Expired => 'slate',
        };
    }
}
