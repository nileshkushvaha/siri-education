<?php

declare(strict_types=1);

namespace App\Quality\Events;

use App\Models\InstructorQualityAlert;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An admin started investigating an open alert. After-commit only. No listener attached in Phase 17N. */
final class InstructorQualityAlertReviewStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorQualityAlert $alert,
    ) {}
}
