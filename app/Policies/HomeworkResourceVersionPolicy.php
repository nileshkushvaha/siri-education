<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HomeworkResourceVersion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Builder;

/**
 * GAP-022 (37A) — requirement #7: a student/instructor may view a
 * version only through a homework assignment they are actually a party
 * to; owning the parent HomeworkResource also grants access (the
 * library owner can always preview their own published versions).
 */
class HomeworkResourceVersionPolicy
{
    use HandlesAuthorization;

    public function view(User $user, HomeworkResourceVersion $version): bool
    {
        if ($user->id === $version->resource->instructor_id) {
            return true;
        }

        return $version->assignments()
            ->where(fn (Builder $query) => $query->where('teacher_id', $user->id)->orWhere('student_id', $user->id))
            ->exists();
    }
}
