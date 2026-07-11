<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Compensation-agreement authorization. Only finance-authorized staff
 * mutate agreements; instructors may view their own agreed compensation
 * and history (internal reasons/notes stay hidden at the model layer)
 * but can never create or change it. `super_admin` bypasses via
 * Gate::before().
 */
class InstructorCompensationAgreementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorCompensationAgreement');
    }

    public function view(User $user, InstructorCompensationAgreement $agreement): bool
    {
        return $user->id === $agreement->instructor_id
            || $this->hasPermission($user, 'View:InstructorCompensationAgreement');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:InstructorCompensationAgreement');
    }

    public function update(User $user, InstructorCompensationAgreement $agreement): bool
    {
        // Active financial terms are immutable; draft/scheduled edits go
        // through dedicated service actions, never a generic update.
        return false;
    }

    public function delete(User $user, InstructorCompensationAgreement $agreement): bool
    {
        // Compensation history is never deleted.
        return false;
    }

    public function schedule(User $user, InstructorCompensationAgreement $agreement): bool
    {
        return $this->hasPermission($user, 'Schedule:InstructorCompensationAgreement');
    }

    public function activate(User $user, InstructorCompensationAgreement $agreement): bool
    {
        return $this->hasPermission($user, 'Activate:InstructorCompensationAgreement');
    }

    public function end(User $user, InstructorCompensationAgreement $agreement): bool
    {
        return $this->hasPermission($user, 'End:InstructorCompensationAgreement');
    }

    public function cancel(User $user, InstructorCompensationAgreement $agreement): bool
    {
        return $this->hasPermission($user, 'Cancel:InstructorCompensationAgreement');
    }

    /** Replacing = ending + creating; require both grants via Configure. */
    public function replace(User $user, InstructorCompensationAgreement $agreement): bool
    {
        return $this->hasPermission($user, 'Configure:InstructorCompensationAgreement');
    }

    public function viewHistory(User $user, InstructorCompensationAgreement $agreement): bool
    {
        return $user->id === $agreement->instructor_id
            || $this->hasPermission($user, 'ViewHistory:InstructorCompensationAgreement');
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
