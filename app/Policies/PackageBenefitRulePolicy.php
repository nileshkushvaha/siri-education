<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PackageBenefitRule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Admin-only rule-authoring authorization. `super_admin` bypasses via
 * Gate::before() — never replicated here.
 */
class PackageBenefitRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:PackageBenefitRule');
    }

    public function view(User $user, PackageBenefitRule $rule): bool
    {
        return $this->hasPermission($user, 'View:PackageBenefitRule');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:PackageBenefitRule');
    }

    public function update(User $user, PackageBenefitRule $rule): bool
    {
        return $this->hasPermission($user, 'Update:PackageBenefitRule');
    }

    public function delete(User $user, PackageBenefitRule $rule): bool
    {
        return $this->hasPermission($user, 'Delete:PackageBenefitRule');
    }

    /** Historical: proposals snapshot from this rule; physical deletion is never permitted regardless of permission. PreventsHardDeletion enforces the same rule at the model layer. */
    public function forceDelete(User $user, PackageBenefitRule $rule): bool
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
