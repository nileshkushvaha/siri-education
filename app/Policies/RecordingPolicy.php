<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Recording;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * GAP-028 requirement #6 — only the lesson's own student/instructor, or
 * an admin explicitly holding View:Recording, may access a recording.
 * Gate::before still grants super_admin regardless of this policy.
 */
class RecordingPolicy
{
    use HandlesAuthorization;

    /** Admin list access only — a student/instructor never browses "all recordings", only their own via the download route. */
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Recording');
    }

    public function view(User $user, Recording $recording): bool
    {
        if ($user->id === $recording->student_id || $user->id === $recording->teacher_id) {
            return true;
        }

        return $user->can('View:Recording');
    }
}
