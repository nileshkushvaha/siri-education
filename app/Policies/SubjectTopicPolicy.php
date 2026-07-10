<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SubjectTopic;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SubjectTopicPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SubjectTopic');
    }

    public function view(AuthUser $authUser, SubjectTopic $subjectTopic): bool
    {
        return $authUser->can('View:SubjectTopic');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SubjectTopic');
    }

    public function update(AuthUser $authUser, SubjectTopic $subjectTopic): bool
    {
        return $authUser->can('Update:SubjectTopic');
    }

    public function delete(AuthUser $authUser, SubjectTopic $subjectTopic): bool
    {
        return $authUser->can('Delete:SubjectTopic');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SubjectTopic');
    }

    public function restore(AuthUser $authUser, SubjectTopic $subjectTopic): bool
    {
        return $authUser->can('Restore:SubjectTopic');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SubjectTopic');
    }

    public function forceDelete(AuthUser $authUser, SubjectTopic $subjectTopic): bool
    {
        return $authUser->can('ForceDelete:SubjectTopic');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SubjectTopic');
    }

    public function replicate(AuthUser $authUser, SubjectTopic $subjectTopic): bool
    {
        return $authUser->can('Replicate:SubjectTopic');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SubjectTopic');
    }
}
