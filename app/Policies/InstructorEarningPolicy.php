<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorEarning;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Earning authorization. The instructor sees only their own earnings
 * (and even then never the admin-only student amount / margin — those
 * are hidden at the model layer); every mutation is staff-permission
 * gated. `super_admin` bypasses via Gate::before().
 */
class InstructorEarningPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorEarning');
    }

    public function view(User $user, InstructorEarning $earning): bool
    {
        return $user->id === $earning->instructor_id
            || $this->hasPermission($user, 'View:InstructorEarning');
    }

    public function create(User $user): bool
    {
        // Earnings are created by the lifecycle engine, never manually.
        return false;
    }

    public function update(User $user, InstructorEarning $earning): bool
    {
        return $this->hasPermission($user, 'Update:InstructorEarning');
    }

    public function delete(User $user, InstructorEarning $earning): bool
    {
        return $this->hasPermission($user, 'Delete:InstructorEarning');
    }

    public function release(User $user, InstructorEarning $earning): bool
    {
        return $this->hasPermission($user, 'Release:InstructorEarning');
    }

    public function reverse(User $user, InstructorEarning $earning): bool
    {
        return $this->hasPermission($user, 'Reverse:InstructorEarning');
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
