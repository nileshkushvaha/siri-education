<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Read access only — every reward mutation (approve/reject/retry/
 * reverse) is a dedicated ReferralRewardService method that authorizes
 * its own named permission independently. Filament never creates,
 * updates, or deletes reward rows.
 */
class ReferralRewardPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewReferralRewards');
    }

    public function view(User $user, ReferralReward $reward): bool
    {
        return $this->hasPermission($user, 'ViewReferralRewards');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReferralReward $reward): bool
    {
        return false;
    }

    public function delete(User $user, ReferralReward $reward): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReferralReward $reward): bool
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
