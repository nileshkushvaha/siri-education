<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/** What the platform owes (or does not owe) the student for a finalized lesson. Classification only — never execution. */
enum LessonStudentDisposition: string
{
    case None = 'none';
    case FullWalletRefundRequired = 'full_wallet_refund_required';
    case PolicyReviewRequired = 'policy_review_required';

    /** The booking cancellation pipeline already owns the refund — never duplicated here. */
    case ExistingCancellationFlow = 'existing_cancellation_flow';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::FullWalletRefundRequired => 'Full Wallet Refund Required',
            self::PolicyReviewRequired => 'Policy Review Required',
            self::ExistingCancellationFlow => 'Existing Cancellation Flow',
        };
    }
}
