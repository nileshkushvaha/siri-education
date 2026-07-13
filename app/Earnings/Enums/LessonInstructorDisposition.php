<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/** What happens to the instructor's compensation for a finalized lesson. Classification only — never execution. */
enum LessonInstructorDisposition: string
{
    /** The Phase 14 LessonCompleted → earning pipeline owns this — never duplicated here. */
    case ExistingCompletionEarning = 'existing_completion_earning';

    case CompensationReviewRequired = 'compensation_review_required';
    case NoEarning = 'no_earning';
    case HoldExistingEarning = 'hold_existing_earning';

    /** The cancellation pipeline (earning reversal listener) already owns this. */
    case ExistingCancellationFlow = 'existing_cancellation_flow';

    public function label(): string
    {
        return match ($this) {
            self::ExistingCompletionEarning => 'Existing Completion Earning',
            self::CompensationReviewRequired => 'Compensation Review Required',
            self::NoEarning => 'No Earning',
            self::HoldExistingEarning => 'Hold Existing Earning',
            self::ExistingCancellationFlow => 'Existing Cancellation Flow',
        };
    }
}
