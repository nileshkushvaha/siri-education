<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MessageSafetyFinding;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Compliance staff only — reusing the existing suspicious-activity
 * permissions rather than minting a parallel set, so an organisation
 * that has already decided who reviews compliance signals does not have
 * to decide again.
 *
 * NEITHER PARTICIPANT MAY SEE A FINDING, including the person who wrote
 * the message. Showing someone an unreviewed, possibly wrong,
 * machine-generated suspicion about their own words would be worse than
 * useless: it teaches evasion, invites argument with a classifier, and
 * for a student — often a minor — amounts to being accused by software.
 * What a user sees is the pre-send warning, which is advisory, instant
 * and never recorded.
 *
 * There is no `resolve`-style enforcement ability here at all, because
 * no enforcement follows from a finding. Confirming records agreement;
 * restricting an account remains a separate, explicit admin action in
 * its own domain.
 *
 * `super_admin` bypasses via Gate::before() — never replicated here.
 */
class MessageSafetyFindingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:SuspiciousActivityFlag');
    }

    public function view(User $user, MessageSafetyFinding $finding): bool
    {
        return $this->hasPermission($user, 'View:SuspiciousActivityFlag')
            || $this->hasPermission($user, 'ViewAny:SuspiciousActivityFlag');
    }

    /** Confirming or dismissing — the same right as resolving a compliance flag. */
    public function review(User $user, MessageSafetyFinding $finding): bool
    {
        return $this->hasPermission($user, 'Resolve:SuspiciousActivityFlag');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MessageSafetyFinding $finding): bool
    {
        return false;
    }

    public function delete(User $user, MessageSafetyFinding $finding): bool
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
