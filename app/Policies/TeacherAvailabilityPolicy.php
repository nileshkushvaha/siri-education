<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TeacherAvailability;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class TeacherAvailabilityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:TeacherAvailability');
    }

    public function view(User $user, TeacherAvailability $availability): bool
    {
        return $this->hasPermission($user, 'View:TeacherAvailability');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:TeacherAvailability');
    }

    public function update(User $user, TeacherAvailability $availability): bool
    {
        return $this->hasPermission($user, 'Update:TeacherAvailability');
    }

    public function delete(User $user, TeacherAvailability $availability): bool
    {
        return $this->hasPermission($user, 'Delete:TeacherAvailability');
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
