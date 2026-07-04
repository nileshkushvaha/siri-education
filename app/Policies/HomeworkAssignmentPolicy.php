<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class HomeworkAssignmentPolicy
{
    use HandlesAuthorization;

    public function view(User $user, HomeworkAssignment $assignment): bool
    {
        return $user->id === $assignment->student_id || $user->id === $assignment->teacher_id;
    }

    public function submit(User $user, HomeworkAssignment $assignment): bool
    {
        return $user->id === $assignment->student_id;
    }
}
