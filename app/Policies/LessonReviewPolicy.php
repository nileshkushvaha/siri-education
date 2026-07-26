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
 * instructor being reviewed has no ability here at all. `report` is the
 * one ability here open to any active authenticated user, not staff-only.
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

    /**
     * Approve, reject, restore, or archive — staff only, no student
     * bypass. Students never hold `Moderate:LessonReview` at all, so
     * they're denied structurally; the instructor-identity exclusion
     * is defense-in-depth for a future role change that might otherwise
     * let an instructor moderate a review about their own teaching,
     * mirroring ReviewReportPolicy::resolve().
     */
    public function moderate(User $user, LessonReview $review): bool
    {
        return $user->id !== $review->instructor_id
            && $this->hasPermission($user, 'Moderate:LessonReview');
    }

    /** Hide a live published review — separately permissioned, staff only; same instructor exclusion as moderate(). */
    public function hide(User $user, LessonReview $review): bool
    {
        return $user->id !== $review->instructor_id
            && $this->hasPermission($user, 'Hide:LessonReview');
    }

    /** Any authenticated, active user may report an eligible public review — not staff-only, unlike every other ability here. */
    public function report(User $user, LessonReview $review): bool
    {
        return $user->isActive() && $this->hasPermission($user, 'Report:LessonReview');
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
