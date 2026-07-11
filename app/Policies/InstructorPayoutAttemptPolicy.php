<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorPayoutAttempt;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Payout-attempt authorization. Instructors never appear here at all —
 * they cannot execute, retry, cancel, or reconcile their own payout
 * (invariant #7). Every action is staff-permission gated; `super_admin`
 * bypasses via Gate::before().
 */
class InstructorPayoutAttemptPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorPayoutAttempt');
    }

    public function view(User $user, InstructorPayoutAttempt $attempt): bool
    {
        return $this->hasPermission($user, 'View:InstructorPayoutAttempt');
    }

    /** Immutable financial record — no direct edits or deletes ever. */
    public function update(User $user, InstructorPayoutAttempt $attempt): bool
    {
        return false;
    }

    public function delete(User $user, InstructorPayoutAttempt $attempt): bool
    {
        return false;
    }

    public function cancel(User $user, InstructorPayoutAttempt $attempt): bool
    {
        return $this->hasPermission($user, 'Cancel:InstructorPayoutAttempt');
    }

    public function reconcile(User $user, InstructorPayoutAttempt $attempt): bool
    {
        return $this->hasPermission($user, 'Reconcile:InstructorPayoutAttempt');
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
