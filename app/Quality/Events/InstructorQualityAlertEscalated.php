<?php

declare(strict_types=1);

namespace App\Quality\Events;

use App\Models\InstructorQualityAlert;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A repeated-type alert was created for an instructor who already has
 * at least one prior *terminal* (resolved/dismissed/duplicate) alert
 * of the same type — the same quality problem recurring after a past
 * resolution. Dispatched alongside InstructorQualityAlertCreated, not
 * instead of it. After-commit only. No listener attached in Phase 17N.
 */
final class InstructorQualityAlertEscalated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorQualityAlert $alert,
        public readonly int $priorEpisodeCount,
    ) {}
}
