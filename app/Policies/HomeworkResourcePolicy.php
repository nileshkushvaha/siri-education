<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HomeworkResource;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * GAP-022 (37A) — SRS §7.15/7.18-FR#8: the resource library is a
 * personal, private library. An instructor cannot browse or manage
 * another instructor's library (requirement #7); Gate::before still
 * grants super_admin the "explicit admin permission" escape hatch.
 */
class HomeworkResourcePolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        return $user->hasRole('instructor');
    }

    public function view(User $user, HomeworkResource $resource): bool
    {
        return $user->id === $resource->instructor_id;
    }

    public function update(User $user, HomeworkResource $resource): bool
    {
        return $user->id === $resource->instructor_id;
    }
}
