<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class HomeworkAssignmentPolicy
{
    use HandlesAuthorization;

    /**
     * Only instructors create assignments; the lesson/plan ownership
     * itself is enforced by HomeworkContextValidator inside
     * HomeworkService::assign().
     */
    public function create(User $user): bool
    {
        return $user->hasRole('instructor');
    }

    public function view(User $user, HomeworkAssignment $assignment): bool
    {
        return $user->id === $assignment->student_id || $user->id === $assignment->teacher_id;
    }

    public function submit(User $user, HomeworkAssignment $assignment): bool
    {
        return $user->id === $assignment->student_id;
    }

    public function review(User $user, HomeworkAssignment $assignment): bool
    {
        return $user->id === $assignment->teacher_id;
    }

    /** Only the assigning instructor manages instructor-provided resources. */
    public function manageResources(User $user, HomeworkAssignment $assignment): bool
    {
        return $user->id === $assignment->teacher_id;
    }
}
