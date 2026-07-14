<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Instructor-to-student feedback authorization. Relationship-based for
 * the instructor (only the assigned instructor may submit or view
 * their own submission); staff viewing is permission-gated. Students
 * cannot submit or view in this phase — the SRS does not define
 * student visibility yet (documented as a future policy decision, not
 * assumed here). No moderation, review-report, or quality-alert
 * permission is granted through this policy. `super_admin` bypasses
 * via Gate::before().
 */
class InstructorStudentFeedbackPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorStudentFeedback');
    }

    public function view(User $user, InstructorStudentFeedback $feedback): bool
    {
        return $user->id === $feedback->instructor_id
            || $this->hasPermission($user, 'View:InstructorStudentFeedback');
    }

    /** Only the lesson's assigned instructor may submit — checked against the lesson, not an existing feedback row. */
    public function submit(User $user, Lesson $lesson): bool
    {
        return $user->id === $lesson->instructor_id && $user->hasRole('instructor');
    }

    /** Feedback is immutable after creation — no role may update it. */
    public function update(User $user, InstructorStudentFeedback $feedback): bool
    {
        return false;
    }

    /** Feedback is never physically deleted. */
    public function delete(User $user, InstructorStudentFeedback $feedback): bool
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
