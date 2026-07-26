<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReferralCampaign;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Two word-style permissions, matching the referral module's permission
 * set (ViewReferralAttributions / DisableReferralCodes): read access via
 * ViewReferralCampaigns, every mutation via ManageReferralCampaigns.
 * Deletion is denied outright — campaigns are archived, never deleted
 * (the model's PreventsHardDeletion is the second lock on that door).
 * super_admin bypasses via Gate::before().
 */
class ReferralCampaignPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewReferralCampaigns');
    }

    public function view(User $user, ReferralCampaign $campaign): bool
    {
        return $this->hasPermission($user, 'ViewReferralCampaigns');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'ManageReferralCampaigns');
    }

    public function update(User $user, ReferralCampaign $campaign): bool
    {
        return $this->hasPermission($user, 'ManageReferralCampaigns');
    }

    public function delete(User $user, ReferralCampaign $campaign): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReferralCampaign $campaign): bool
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
