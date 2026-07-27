<?php

declare(strict_types=1);

namespace App\Feedback\Events;

use App\Models\InstructorStudentFeedback;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An instructor submitted private feedback about a student's
 * participation in a completed lesson. After-commit only. No
 * notification, Learning Plan, quality-alert, report, analytics, or
 * scoring listener is attached — those integrations are out of scope.
 */
final class InstructorStudentFeedbackSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorStudentFeedback $feedback,
    ) {}
}
