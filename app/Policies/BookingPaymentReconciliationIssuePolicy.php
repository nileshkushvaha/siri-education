<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BookingPaymentReconciliationIssue;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Reconciliation-issue authorization for the collection domain —
 * mirrors InstructorPayoutReconciliationIssuePolicy exactly. Finance/
 * ops only, never the student. Resolution never implies mark-paid: it
 * only closes the issue row, gated separately from any payment-state
 * mutation.
 */
class BookingPaymentReconciliationIssuePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:BookingPaymentReconciliationIssue');
    }

    public function view(User $user, BookingPaymentReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'View:BookingPaymentReconciliationIssue');
    }

    public function update(User $user, BookingPaymentReconciliationIssue $issue): bool
    {
        return false;
    }

    public function delete(User $user, BookingPaymentReconciliationIssue $issue): bool
    {
        return false;
    }

    public function assign(User $user, BookingPaymentReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'Assign:BookingPaymentReconciliationIssue');
    }

    public function resolve(User $user, BookingPaymentReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'Resolve:BookingPaymentReconciliationIssue');
    }

    public function reconcileNow(User $user, BookingPaymentReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'ReconcileNow:BookingPaymentReconciliationIssue');
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
