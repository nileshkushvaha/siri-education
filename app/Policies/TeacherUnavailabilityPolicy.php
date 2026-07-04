<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TeacherUnavailability;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class TeacherUnavailabilityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:TeacherUnavailability');
    }

    public function view(User $user, TeacherUnavailability $leave): bool
    {
        return $this->hasPermission($user, 'View:TeacherUnavailability');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:TeacherUnavailability');
    }

    public function update(User $user, TeacherUnavailability $leave): bool
    {
        return $this->hasPermission($user, 'Update:TeacherUnavailability');
    }

    public function delete(User $user, TeacherUnavailability $leave): bool
    {
        return $this->hasPermission($user, 'Delete:TeacherUnavailability');
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
