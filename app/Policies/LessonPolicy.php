<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Lesson authorization. Participants (student/instructor) see their own
 * lessons; the instructor may run the attendance/completion lifecycle
 * for lessons they teach; staff act through explicit permissions.
 * `super_admin` bypasses via Gate::before() — never replicated here.
 */
class LessonPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:Lesson');
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->isParticipant($user, $lesson)
            || $this->hasPermission($user, 'View:Lesson');
    }

    public function create(User $user): bool
    {
        // Lessons are created by the lifecycle engine, never manually.
        return false;
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'Update:Lesson');
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'Delete:Lesson');
    }

    public function restore(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'Restore:Lesson');
    }

    public function forceDelete(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'ForceDelete:Lesson');
    }

    public function markAttendance(User $user, Lesson $lesson): bool
    {
        return $this->isInstructor($user, $lesson)
            || $this->hasPermission($user, 'MarkAttendance:Lesson');
    }

    public function complete(User $user, Lesson $lesson): bool
    {
        return $this->isInstructor($user, $lesson)
            || $this->hasPermission($user, 'Complete:Lesson');
    }

    /**
     * Correct a finalized lesson outcome — explicit staff permission
     * only; participants can never rewrite outcomes.
     */
    public function overrideOutcome(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'OverrideOutcome:Lesson');
    }

    /** Submit an attendance claim for one's own lesson (either participant). */
    public function submitAttendance(User $user, Lesson $lesson): bool
    {
        return $this->isParticipant($user, $lesson);
    }

    /** Report a meeting problem for one's own lesson (either participant). */
    public function reportTechnicalIssue(User $user, Lesson $lesson): bool
    {
        return $this->isParticipant($user, $lesson);
    }

    /** Review attendance claims, technical-issue reports, and ambiguous evidence — staff only. */
    public function reviewAttendance(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'ReviewAttendance:Lesson');
    }

    /** Resolve the lesson's financial disposition — staff only, never participants. */
    public function resolveFinancialDisposition(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'ResolveFinancialDisposition:Lesson');
    }

    /**
     * Inspect the attendance record and its evidence log — staff
     * permission, or a participant looking at their own lesson.
     */
    public function inspectAttendance(User $user, Lesson $lesson): bool
    {
        return $this->isParticipant($user, $lesson)
            || $this->hasPermission($user, 'InspectAttendance:Lesson');
    }

    public function dispute(User $user, Lesson $lesson): bool
    {
        return $this->isParticipant($user, $lesson)
            || $this->hasPermission($user, 'Dispute:Lesson');
    }

    public function cancel(User $user, Lesson $lesson): bool
    {
        return $this->hasPermission($user, 'Cancel:Lesson');
    }

    private function isParticipant(User $user, Lesson $lesson): bool
    {
        return $user->id === $lesson->student_id || $this->isInstructor($user, $lesson);
    }

    private function isInstructor(User $user, Lesson $lesson): bool
    {
        return $user->id === $lesson->instructor_id;
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
