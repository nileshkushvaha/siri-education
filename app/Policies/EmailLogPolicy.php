<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class EmailLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo('ViewAny:EmailLog');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }
}
