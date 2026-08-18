<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Enums;

/**
 * The lifecycle of one AI feedback draft.
 *
 *   Pending    requested by the instructor; the queued run is in flight
 *   Ready      a validated draft is waiting for the instructor
 *   Used       the instructor pulled the draft into their editor
 *   Discarded  the instructor dismissed it
 *   Failed     the run produced nothing usable (reason recorded)
 *
 * `Used` records PROVENANCE, not publication: it means the draft text
 * was placed in the instructor's editor, never that it was published.
 * What reaches the student is whatever the instructor finally typed and
 * submitted, stored separately on the assignment — this table and that
 * field are never the same text by construction.
 */
enum HomeworkFeedbackDraftStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Used = 'used';
    case Discarded = 'discarded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Generating',
            self::Ready => 'Draft ready',
            self::Used => 'Used as a starting point',
            self::Discarded => 'Discarded',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** Only a Ready draft can be acted on; the rest are history. */
    public function isActionable(): bool
    {
        return $this === self::Ready;
    }
}
