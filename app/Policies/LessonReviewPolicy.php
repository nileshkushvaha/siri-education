<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LessonReview;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Submitted-review authorization. Visible only to the reviewing
 * student and permissioned staff. No create/update/delete ability is
 * defined — reviews are written exclusively by SubmitLessonReviewAction
 * and are never edited or deleted through any policy path; the
 * instructor being reviewed has no ability here at all.
 */
class LessonReviewPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:LessonReview');
    }

    public function view(User $user, LessonReview $review): bool
    {
        return $user->id === $review->student_id
            || $this->hasPermission($user, 'View:LessonReview');
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
