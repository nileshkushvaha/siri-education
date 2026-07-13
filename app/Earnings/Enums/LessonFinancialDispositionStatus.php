<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/** Processing state of a lesson's financial disposition. */
enum LessonFinancialDispositionStatus: string
{
    case Pending = 'pending';

    /** Classification is confident — awaits the (future) execution phase. */
    case Ready = 'ready';

    /** Money is parked (earning hold placed / admin hold) until a decision. */
    case Held = 'held';

    /** A human must decide (policy review, settled-earning conflict, refund-already-completed). */
    case ManualReview = 'manual_review';

    /** Existing pipelines own everything — nothing for this bridge to do. */
    case NoAction = 'no_action';

    /** An authorized admin decided — terminal for this record version. */
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Ready => 'Ready',
            self::Held => 'Held',
            self::ManualReview => 'Manual Review',
            self::NoAction => 'No Action',
            self::Resolved => 'Resolved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Ready => 'info',
            self::Held => 'warning',
            self::ManualReview => 'danger',
            self::NoAction => 'gray',
            self::Resolved => 'success',
        };
    }

    /** Open for an admin resolution decision. */
    public function isResolvable(): bool
    {
        return match ($this) {
            self::Ready, self::Held, self::ManualReview, self::Pending => true,
            self::NoAction, self::Resolved => false,
        };
    }
}
