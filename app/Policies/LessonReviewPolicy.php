<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LessonReview;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Submitted-review authorization. Visible only to the reviewing
 * student and permissioned staff. No create/update/delete/edit
 * ability is defined — reviews are written exclusively by
 * SubmitLessonReviewAction and their content is never edited through
 * any policy path. Status moderation (approve/reject/restore/archive
 * vs. hide) is staff-only via two explicit permissions; students
 * cannot publish, reject, hide, or restore their own review, and the
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

    /** Approve, reject, restore, or archive — staff only, no student/instructor bypass. */
    public function moderate(User $user, LessonReview $review): bool
    {
        return $this->hasPermission($user, 'Moderate:LessonReview');
    }

    /** Hide a live published review — separately permissioned, staff only. */
    public function hide(User $user, LessonReview $review): bool
    {
        return $this->hasPermission($user, 'Hide:LessonReview');
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
