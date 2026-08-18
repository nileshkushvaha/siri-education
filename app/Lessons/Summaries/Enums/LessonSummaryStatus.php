<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Enums;

/**
 * The lifecycle of one AI-assisted lesson summary.
 *
 *   Pending    the instructor asked; the queued run is in flight
 *   Ready      a validated draft is waiting for the instructor
 *   Approved   the instructor edited and accepted it — their text is
 *              now the lesson's summary of record
 *   Discarded  the instructor rejected the draft
 *   Failed     the run produced nothing usable (reason recorded)
 *
 * Approved is the only state in which a summary counts as documentation
 * of the lesson, and reaching it always requires an explicit instructor
 * action. A Ready draft is a suggestion and nothing more — no report,
 * timeline, progress calculation or student surface reads one.
 */
enum LessonSummaryStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Approved = 'approved';
    case Discarded = 'discarded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Generating',
            self::Ready => 'Draft ready',
            self::Approved => 'Approved',
            self::Discarded => 'Discarded',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** Only a Ready draft can be approved or discarded. */
    public function isActionable(): bool
    {
        return $this === self::Ready;
    }
}
