<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorCompensationException;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The exception queue is part of compensation administration — it
 * reuses the agreement permissions (viewing the queue = ViewAny
 * agreements; retrying = Configure). Rows are system-written and never
 * deleted; there is nothing an instructor can do with them.
 */
class InstructorCompensationExceptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorCompensationAgreement');
    }

    public function view(User $user, InstructorCompensationException $exception): bool
    {
        return $this->hasPermission($user, 'View:InstructorCompensationAgreement');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, InstructorCompensationException $exception): bool
    {
        return false;
    }

    public function delete(User $user, InstructorCompensationException $exception): bool
    {
        return false;
    }

    /** Manually re-run earning creation for one blocked lesson. */
    public function retry(User $user, InstructorCompensationException $exception): bool
    {
        return $this->hasPermission($user, 'Configure:InstructorCompensationAgreement');
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
