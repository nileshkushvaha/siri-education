<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentPackageEntitlement;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Entitlements are read-only to everyone. They are created solely by
 * InstructorPackageProposalService::acceptProposal() and mutated solely
 * by PackageEntitlementService::consumeForLesson() — there is no
 * create/update/delete route for any role, including admin, so those
 * abilities deliberately return false rather than being permission-gated.
 *
 * `super_admin` bypasses via Gate::before() — never replicated here.
 */
class StudentPackageEntitlementPolicy
{
    use HandlesAuthorization;

    /** Gates the Filament admin listing (all students' entitlements). */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:StudentPackageEntitlement');
    }

    /**
     * Admin sees any; the owning student sees their own; the instructor
     * named on the entitlement sees that one (their own student's
     * balance) and no others.
     */
    public function view(User $user, StudentPackageEntitlement $entitlement): bool
    {
        if ($this->hasPermission($user, 'ViewAny:StudentPackageEntitlement')) {
            return true;
        }

        if (! $this->hasPermission($user, 'View:StudentPackageEntitlement')) {
            return false;
        }

        return $user->id === $entitlement->student_id
            || $user->id === $entitlement->instructor_id;
    }

    /** Entitlements are granted by acceptance, never hand-created. */
    public function create(User $user): bool
    {
        return false;
    }

    /** Balance changes only through PackageEntitlementService::consumeForLesson(). */
    public function update(User $user, StudentPackageEntitlement $entitlement): bool
    {
        return false;
    }

    public function delete(User $user, StudentPackageEntitlement $entitlement): bool
    {
        return false;
    }

    /** Historical owned value — PreventsHardDeletion enforces the same at the model layer. */
    public function forceDelete(User $user, StudentPackageEntitlement $entitlement): bool
    {
        return false;
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
