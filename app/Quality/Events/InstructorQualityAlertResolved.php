<?php

declare(strict_types=1);

namespace App\Quality\Events;

use App\Models\InstructorQualityAlert;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An admin resolved an alert (with a recorded recommendation, never an automatic action). After-commit only. No listener is attached to this event. */
final class InstructorQualityAlertResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorQualityAlert $alert,
    ) {}
}
