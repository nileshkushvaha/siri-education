<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OperationalAlert;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** Operational-alert inspection/lifecycle authorization — staff only. No create/edit/delete ability exists. */
class OperationalAlertPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:OperationalAlert');
    }

    public function view(User $user, OperationalAlert $alert): bool
    {
        return $this->hasPermission($user, 'View:OperationalAlert');
    }

    public function acknowledge(User $user, OperationalAlert $alert): bool
    {
        return $this->hasPermission($user, 'Acknowledge:OperationalAlert');
    }

    public function resolve(User $user, OperationalAlert $alert): bool
    {
        return $this->hasPermission($user, 'Resolve:OperationalAlert');
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
