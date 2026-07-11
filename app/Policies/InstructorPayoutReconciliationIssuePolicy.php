<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Reconciliation-issue authorization — finance/ops only, never the
 * instructor. Resolution never implies mark-paid: it only closes the
 * issue row, gated separately from any payout-state mutation.
 */
class InstructorPayoutReconciliationIssuePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorPayoutReconciliationIssue');
    }

    public function view(User $user, InstructorPayoutReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'View:InstructorPayoutReconciliationIssue');
    }

    public function update(User $user, InstructorPayoutReconciliationIssue $issue): bool
    {
        return false;
    }

    public function delete(User $user, InstructorPayoutReconciliationIssue $issue): bool
    {
        return false;
    }

    public function assign(User $user, InstructorPayoutReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'Assign:InstructorPayoutReconciliationIssue');
    }

    public function resolve(User $user, InstructorPayoutReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'Resolve:InstructorPayoutReconciliationIssue');
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
