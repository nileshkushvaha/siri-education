<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\StudentRoleNotAssignableException;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The one rule about the student role: it is granted when an account is
 * created (self-registration with "I want to learn", or an admin creating
 * the user) and never afterwards. A student may later apply to teach and
 * hold both roles; an account that never held `student` cannot acquire it,
 * so an instructor never "becomes" a student. Stateless per-user predicate,
 * deliberately separate from SuperAdminGuardService's counted invariant.
 */
final class StudentRoleAssignmentGuard
{
    public const string STUDENT_ROLE = 'student';

    /** Existing holders keep it; nobody else may gain it. */
    public function mayHoldStudentRole(User $user): bool
    {
        return $user->hasRole(self::STUDENT_ROLE);
    }

    /**
     * Checks a submitted role selection for an EXISTING account before it
     * is written (the admin panel has no per-page transaction to roll
     * back). Null means "roles untouched".
     *
     * @param  array<int, int|string>|null  $submittedRoleIds
     *
     * @throws StudentRoleNotAssignableException
     */
    public function assertSubmittedRolesAllowed(User $record, ?array $submittedRoleIds): void
    {
        if ($submittedRoleIds === null || $this->mayHoldStudentRole($record)) {
            return;
        }

        $addsStudent = Role::query()
            ->whereIn('id', $submittedRoleIds)
            ->where('name', self::STUDENT_ROLE)
            ->where('guard_name', 'web')
            ->exists();

        if ($addsStudent) {
            throw StudentRoleNotAssignableException::make();
        }
    }

    public function refusalMessage(): string
    {
        return StudentRoleNotAssignableException::make()->getMessage();
    }
}
