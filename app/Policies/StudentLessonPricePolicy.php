<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentLessonPrice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** Admin-only pricing matrix — never granted to the instructor role (see phase-10.2d docs). */
class StudentLessonPricePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:StudentLessonPrice');
    }

    public function view(User $user, StudentLessonPrice $studentLessonPrice): bool
    {
        return $this->hasPermission($user, 'View:StudentLessonPrice');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'Create:StudentLessonPrice');
    }

    public function update(User $user, StudentLessonPrice $studentLessonPrice): bool
    {
        return $this->hasPermission($user, 'Update:StudentLessonPrice');
    }

    public function delete(User $user, StudentLessonPrice $studentLessonPrice): bool
    {
        return $this->hasPermission($user, 'Delete:StudentLessonPrice');
    }

    public function restore(User $user, StudentLessonPrice $studentLessonPrice): bool
    {
        return $this->hasPermission($user, 'Restore:StudentLessonPrice');
    }

    public function forceDelete(User $user, StudentLessonPrice $studentLessonPrice): bool
    {
        return $this->hasPermission($user, 'ForceDelete:StudentLessonPrice');
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
