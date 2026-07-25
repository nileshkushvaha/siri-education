<?php

declare(strict_types=1);

namespace App\Compliance\Events;

use App\Models\SuspiciousActivityFlag;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new suspicious-activity flag was created (not merged into an
 * existing one — a repeat occurrence against an already-active flag
 * never re-dispatches this). After-commit only, so a listener can
 * never observe a flag row that a rolled-back transaction later
 * erased.
 */
final class SuspiciousActivityFlagRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SuspiciousActivityFlag $flag,
    ) {}
}
