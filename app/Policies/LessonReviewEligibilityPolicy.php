<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LessonReviewEligibility;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Review-eligibility authorization. Visible only to the eligible
 * student and permissioned staff — the instructor being reviewed has
 * no ability here at all, by design (they must never see, let alone
 * influence, whether or how their own review eligibility exists).
 * Eligibility is written exclusively by ReviewEligibilityService and
 * its actions (system/internal), never through a user-facing
 * create/update/delete ability — none is defined.
 */
class LessonReviewEligibilityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:LessonReviewEligibility');
    }

    public function view(User $user, LessonReviewEligibility $eligibility): bool
    {
        return $user->id === $eligibility->student_id
            || $this->hasPermission($user, 'View:LessonReviewEligibility');
    }

    /**
     * Submit a review against this eligibility — the eligibility's own
     * student only. Deliberately no staff bypass: nobody submits AS
     * the student, not even an administrator.
     */
    public function submitReview(User $user, LessonReviewEligibility $eligibility): bool
    {
        return $user->id === $eligibility->student_id;
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
