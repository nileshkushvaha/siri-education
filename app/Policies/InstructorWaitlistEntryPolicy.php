<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorWaitlistEntry;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The owning student may view their own entry; the instructor named
 * on the entry may view it too (SRS §10.28/§10.29 instructor demand
 * visibility, never another student's private details beyond what
 * the entry itself carries). Staff need the explicit Shield
 * permission. No create/update/delete ability is ever granted here —
 * WaitlistService is the only writer, and no user-facing action
 * bypasses it.
 */
class InstructorWaitlistEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorWaitlistEntry');
    }

    public function view(User $user, InstructorWaitlistEntry $entry): bool
    {
        return $user->id === $entry->student_user_id
            || $user->id === $entry->instructor_user_id
            || $this->hasPermission($user, 'View:InstructorWaitlistEntry');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, InstructorWaitlistEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, InstructorWaitlistEntry $entry): bool
    {
        return false;
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
