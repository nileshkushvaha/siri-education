<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InstructorStatus;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Named gates for instructor public-profile viewing.
 * Registered via Gate::define() — not as a model policy — because User is
 * already auto-bound to UserPolicy by Laravel convention.
 */
class InstructorPolicy
{
    use HandlesAuthorization;

    public function viewAny(): bool
    {
        return true;
    }

    public function view(?User $authUser, User $instructor): bool
    {
        $profile = $instructor->profile;

        if (! $profile) {
            return false;
        }

        if ($authUser !== null && ($authUser->id === $instructor->id || $this->hasPermission($authUser, 'Update:User'))) {
            return true;
        }

        if ($profile->profile_visibility !== 'public') {
            return false;
        }

        return $instructor->isActive()
            && in_array($profile->instructor_status, InstructorStatus::bookable(), true);
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
