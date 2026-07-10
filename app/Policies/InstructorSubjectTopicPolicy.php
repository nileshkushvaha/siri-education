<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorSubjectTopic;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InstructorSubjectTopicPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InstructorSubjectTopic');
    }

    public function view(AuthUser $authUser, InstructorSubjectTopic $instructorSubjectTopic): bool
    {
        return $authUser->can('View:InstructorSubjectTopic');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InstructorSubjectTopic');
    }

    public function update(AuthUser $authUser, InstructorSubjectTopic $instructorSubjectTopic): bool
    {
        return $authUser->can('Update:InstructorSubjectTopic');
    }

    public function delete(AuthUser $authUser, InstructorSubjectTopic $instructorSubjectTopic): bool
    {
        return $authUser->can('Delete:InstructorSubjectTopic');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InstructorSubjectTopic');
    }

    public function restore(AuthUser $authUser, InstructorSubjectTopic $instructorSubjectTopic): bool
    {
        return $authUser->can('Restore:InstructorSubjectTopic');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InstructorSubjectTopic');
    }

    public function forceDelete(AuthUser $authUser, InstructorSubjectTopic $instructorSubjectTopic): bool
    {
        return $authUser->can('ForceDelete:InstructorSubjectTopic');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InstructorSubjectTopic');
    }

    public function replicate(AuthUser $authUser, InstructorSubjectTopic $instructorSubjectTopic): bool
    {
        return $authUser->can('Replicate:InstructorSubjectTopic');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InstructorSubjectTopic');
    }
}
