<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentPackagePurchase;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * A purchase is a financial record: nobody may create, edit, or delete
 * one through a UI. There are deliberately no `create`/`update`/
 * `delete` abilities here at all — the purchase is created by
 * acceptance and moved to Paid only by verified settlement, both inside
 * trusted services.
 *
 * Student: view and pay for their own. Instructor: read-only visibility
 * of the purchase arising from their own proposal — never pay, cancel,
 * or alter it. Admin: read-only listing. `super_admin` bypasses via
 * Gate::before(), never replicated here.
 */
class StudentPackagePurchasePolicy
{
    use HandlesAuthorization;

    /** The Filament admin listing only — the student page scopes by student_id in its own query. */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:StudentPackagePurchase');
    }

    public function view(User $user, StudentPackagePurchase $purchase): bool
    {
        if ($this->hasPermission($user, 'ViewAny:StudentPackagePurchase')) {
            return true;
        }

        if ((int) $user->id === (int) $purchase->student_id) {
            return true;
        }

        // The instructor whose proposal produced this purchase sees its
        // commercial status, and nothing more.
        return (int) $user->id === (int) $purchase->proposal?->instructor_id;
    }

    /** Starting or resuming checkout — the student's own pending purchase only. */
    public function pay(User $user, StudentPackagePurchase $purchase): bool
    {
        return (int) $user->id === (int) $purchase->student_id
            && $purchase->status->isPayable()
            && $this->hasPermission($user, 'Pay:StudentPackagePurchase');
    }

    /**
     * Abandoning one's own open attempt. Gated by the same Pay
     * permission: being able to start a payment and being able to give
     * up on it are one capability, not two.
     */
    public function cancelPaymentAttempt(User $user, StudentPackagePurchase $purchase): bool
    {
        return $this->pay($user, $purchase);
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
