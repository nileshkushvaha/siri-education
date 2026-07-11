<?php

declare(strict_types=1);

namespace App\Policies;

use App\Earnings\Support\InstructorPayoutEligibility;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Payout-method authorization. Instructors manage only their own
 * methods (ownership-scoped, no staff permission needed); every staff
 * action is permission-gated, and decrypting bank details requires the
 * dedicated ViewSensitive permission. `super_admin` bypasses via
 * Gate::before().
 */
class InstructorPayoutMethodPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly InstructorPayoutEligibility $eligibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorPayoutMethod');
    }

    public function view(User $user, InstructorPayoutMethod $method): bool
    {
        return $user->id === $method->instructor_id
            || $this->hasPermission($user, 'View:InstructorPayoutMethod');
    }

    public function create(User $user): bool
    {
        return $this->eligibility->isEligible($user);
    }

    /** Own draft/rejected methods only — verified details are immutable. */
    public function update(User $user, InstructorPayoutMethod $method): bool
    {
        return $user->id === $method->instructor_id
            && $method->status->isEditable();
    }

    public function delete(User $user, InstructorPayoutMethod $method): bool
    {
        // Financial history is never deleted; methods are disabled instead.
        return false;
    }

    public function submit(User $user, InstructorPayoutMethod $method): bool
    {
        return $user->id === $method->instructor_id;
    }

    public function setDefault(User $user, InstructorPayoutMethod $method): bool
    {
        return $user->id === $method->instructor_id;
    }

    public function verify(User $user, InstructorPayoutMethod $method): bool
    {
        return $this->hasPermission($user, 'Verify:InstructorPayoutMethod');
    }

    public function reject(User $user, InstructorPayoutMethod $method): bool
    {
        return $this->hasPermission($user, 'Reject:InstructorPayoutMethod');
    }

    public function disable(User $user, InstructorPayoutMethod $method): bool
    {
        return $user->id === $method->instructor_id
            || $this->hasPermission($user, 'Disable:InstructorPayoutMethod');
    }

    /** Decrypted bank details — dedicated permission, access is audit-logged. */
    public function viewSensitive(User $user, InstructorPayoutMethod $method): bool
    {
        return $this->hasPermission($user, 'ViewSensitive:InstructorPayoutMethod');
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
