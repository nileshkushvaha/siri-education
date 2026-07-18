<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReferralAttribution;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Read access via ViewReferralAttributions; the exceptional correction
 * flow authorizes CorrectReferralAttribution inside
 * ReferralAttributionService itself. No create/update/delete surface —
 * attribution is otherwise permanent registration history.
 */
class ReferralAttributionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewReferralAttributions');
    }

    public function view(User $user, ReferralAttribution $attribution): bool
    {
        return $this->hasPermission($user, 'ViewReferralAttributions');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReferralAttribution $attribution): bool
    {
        return false;
    }

    public function delete(User $user, ReferralAttribution $attribution): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReferralAttribution $attribution): bool
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
