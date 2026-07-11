<?php

declare(strict_types=1);

namespace App\Earnings\Support;

use App\Enums\InstructorStatus;
use App\Models\User;

/**
 * Single definition of "eligible instructor" for the payout domain:
 * an active account holding the instructor role whose application is
 * approved or active. Used by payout-method creation, withdrawal
 * submission, and the policies — never re-derive this elsewhere.
 */
final class InstructorPayoutEligibility
{
    /** Null when eligible; otherwise a UI-safe reason. */
    public function reasonForIneligibility(User $user): ?string
    {
        if (! $user->isActive()) {
            return 'Your account is not active.';
        }

        if (! $user->hasRole('instructor')) {
            return 'Only instructors can manage payouts.';
        }

        $status = $user->profile?->instructor_status;

        if (! in_array($status, [InstructorStatus::Approved, InstructorStatus::Active], strict: true)) {
            return 'Your instructor profile must be approved before you can manage payouts.';
        }

        return null;
    }

    public function isEligible(User $user): bool
    {
        return $this->reasonForIneligibility($user) === null;
    }
}
