<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReviewTag;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Review tag administration — staff only. Tags are never hard-deleted
 * (no `deleted_at` column at all); deactivation goes through `update`,
 * the same as every other field, so there is no separate `delete`
 * ability to define.
 */
class ReviewTagPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:ReviewTag');
    }

    public function view(User $user, ReviewTag $tag): bool
    {
        return $this->hasPermission($user, 'View:ReviewTag');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:ReviewTag');
    }

    /**
     * Nullable $tag so this same ability also answers a class-level
     * check (`can('update', ReviewTag::class)`) for the bulk activate/
     * deactivate actions, which have no single record to bind to.
     */
    public function update(User $user, ?ReviewTag $tag = null): bool
    {
        return $this->hasPermission($user, 'Update:ReviewTag');
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
