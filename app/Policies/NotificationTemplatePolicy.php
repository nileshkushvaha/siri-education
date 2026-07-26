<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Permission-controlled admin access. No create()/delete() ability
 * exists at all: template keys/channels are a fixed, code-owned set
 * (NotificationTemplateRegistry), never administrator-created rows.
 */
class NotificationTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->hasPermission($user, 'ViewAny:NotificationTemplate');
    }

    public function view(User $user, NotificationTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, NotificationTemplate $template): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->hasPermission($user, 'Update:NotificationTemplate');
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
