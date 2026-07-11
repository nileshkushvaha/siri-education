<?php

declare(strict_types=1);

namespace App\Policies;

use App\Earnings\Support\InstructorPayoutEligibility;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Withdrawal authorization. Instructors see and cancel only their own
 * requests; review/approval/rejection are staff-permission gated. The
 * service layer re-validates every business rule — the policy only
 * answers "may this actor attempt it". `super_admin` bypasses via
 * Gate::before().
 */
class InstructorWithdrawalRequestPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly InstructorPayoutEligibility $eligibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorWithdrawalRequest');
    }

    public function view(User $user, InstructorWithdrawalRequest $request): bool
    {
        return $user->id === $request->instructor_id
            || $this->hasPermission($user, 'View:InstructorWithdrawalRequest');
    }

    public function create(User $user): bool
    {
        return $this->eligibility->isEligible($user);
    }

    public function update(User $user, InstructorWithdrawalRequest $request): bool
    {
        // Amounts, destination, and status are immutable — transitions only.
        return false;
    }

    public function delete(User $user, InstructorWithdrawalRequest $request): bool
    {
        // Withdrawal history is never deleted.
        return false;
    }

    public function startReview(User $user, InstructorWithdrawalRequest $request): bool
    {
        return $this->hasPermission($user, 'StartReview:InstructorWithdrawalRequest');
    }

    public function approve(User $user, InstructorWithdrawalRequest $request): bool
    {
        return $this->hasPermission($user, 'Approve:InstructorWithdrawalRequest');
    }

    public function reject(User $user, InstructorWithdrawalRequest $request): bool
    {
        return $this->hasPermission($user, 'Reject:InstructorWithdrawalRequest');
    }

    /** Instructor cancelling their own request (service re-checks state). */
    public function cancel(User $user, InstructorWithdrawalRequest $request): bool
    {
        return $user->id === $request->instructor_id
            && $request->status->isInstructorCancellable();
    }

    public function cancelAsAdmin(User $user, InstructorWithdrawalRequest $request): bool
    {
        return $this->hasPermission($user, 'Cancel:InstructorWithdrawalRequest');
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
