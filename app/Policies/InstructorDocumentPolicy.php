<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Single authorization owner for instructor KYC document access (Phase
 * 23E). Registered via Gate::define() — not as a model policy — for the
 * same reason InstructorPolicy is: User is already auto-bound to
 * UserPolicy by Laravel convention, and this ability is scoped to a
 * UserProfile, not a User.
 *
 * Deliberately does NOT accept View:User/Update:User as a fallback —
 * unlike InstructorOnboardingService::REVIEW_PERMISSION's own generic-
 * permission compatibility bridge, KYC visibility must never widen to
 * "anyone who can edit a user."
 */
class InstructorDocumentPolicy
{
    use HandlesAuthorization;

    public function viewDocuments(User $user, UserProfile $profile): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // "Account exists" / "target profile belongs to an instructor
        // application" — a profile that never applied to teach has no
        // KYC collections worth gating in the first place.
        if ($profile->user === null || $profile->instructor_status === null) {
            return false;
        }

        return $this->hasPermission($user, InstructorOnboardingService::REVIEW_PERMISSION);
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
