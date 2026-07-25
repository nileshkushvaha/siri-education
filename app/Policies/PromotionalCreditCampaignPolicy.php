<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromotionalCreditCampaign;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Mirrors ReferralCampaignPolicy exactly: read access via
 * ViewPromotionalCreditCampaigns, every mutation via
 * ManagePromotionalCreditCampaigns. Deletion is denied outright —
 * campaigns are archived, never deleted (PreventsHardDeletion is the
 * second lock on that door). super_admin bypasses via Gate::before().
 */
class PromotionalCreditCampaignPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewPromotionalCreditCampaigns');
    }

    public function view(User $user, PromotionalCreditCampaign $campaign): bool
    {
        return $this->hasPermission($user, 'ViewPromotionalCreditCampaigns');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'ManagePromotionalCreditCampaigns');
    }

    public function update(User $user, PromotionalCreditCampaign $campaign): bool
    {
        return $this->hasPermission($user, 'ManagePromotionalCreditCampaigns');
    }

    public function delete(User $user, PromotionalCreditCampaign $campaign): bool
    {
        return false;
    }

    public function forceDelete(User $user, PromotionalCreditCampaign $campaign): bool
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
