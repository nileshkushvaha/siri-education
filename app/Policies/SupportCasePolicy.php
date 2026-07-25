<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Owner-or-permission pattern (mirrors InvoicePolicy). "Owner" for a
 * support case means the requester — created_by, or the subject
 * student/instructor for an admin-created case (§25.37 "Students may
 * see: their own cases"). super_admin bypasses via Gate::before(),
 * never replicated here.
 */
class SupportCasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:SupportCase');
    }

    public function view(User $user, SupportCase $case): bool
    {
        return $this->isRequester($user, $case) || $this->hasPermission($user, 'View:SupportCase');
    }

    /** Every authenticated user may open a case for themselves. */
    public function create(User $user): bool
    {
        return true;
    }

    /** Gates the Filament "admin-operational case" create page only. */
    public function createAdminCase(User $user): bool
    {
        return $this->hasPermission($user, 'Create:SupportCase');
    }

    public function update(User $user, SupportCase $case): bool
    {
        return false;
    }

    public function delete(User $user, SupportCase $case): bool
    {
        return false;
    }

    public function reply(User $user, SupportCase $case): bool
    {
        if ($this->isRequester($user, $case)) {
            return ! $case->status->isTerminal();
        }

        return $this->hasPermission($user, 'Reply:SupportCase');
    }

    public function addInternalNote(User $user, SupportCase $case): bool
    {
        return $this->hasPermission($user, 'AddInternalNote:SupportCase');
    }

    public function assign(User $user, SupportCase $case): bool
    {
        return $this->hasPermission($user, 'Assign:SupportCase');
    }

    public function escalate(User $user, SupportCase $case): bool
    {
        return $this->hasPermission($user, 'Escalate:SupportCase');
    }

    public function resolve(User $user, SupportCase $case): bool
    {
        return $this->hasPermission($user, 'Resolve:SupportCase');
    }

    public function close(User $user, SupportCase $case): bool
    {
        return $this->hasPermission($user, 'Close:SupportCase');
    }

    public function reopen(User $user, SupportCase $case): bool
    {
        if (! $case->status->isReopenable()) {
            return false;
        }

        return $this->isRequester($user, $case) || $this->hasPermission($user, 'Reopen:SupportCase');
    }

    private function isRequester(User $user, SupportCase $case): bool
    {
        return $user->id === $case->created_by
            || $user->id === $case->student_id
            || $user->id === $case->instructor_id;
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
