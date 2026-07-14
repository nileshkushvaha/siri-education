<?php

declare(strict_types=1);

namespace App\Quality\Events;

use App\Models\InstructorQualityAlert;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A new quality alert was recorded. After-commit only. No notification/instructor-status listener attached in Phase 17N. */
final class InstructorQualityAlertCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorQualityAlert $alert,
    ) {}
}
