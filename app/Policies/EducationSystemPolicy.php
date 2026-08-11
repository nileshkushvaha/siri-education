<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EducationSystem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Also gates mutation of the Country/AcademicLevel/Curriculum mapping
 * rows — those mapping models have no policy of their own, mirroring
 * how CurriculumModuleTopic has no policy and is instead gated by its
 * owning CurriculumModule's policy checks inside CurriculumService.
 */
class EducationSystemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EducationSystem');
    }

    public function view(AuthUser $authUser, EducationSystem $educationSystem): bool
    {
        return $authUser->can('View:EducationSystem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EducationSystem');
    }

    public function update(AuthUser $authUser, EducationSystem $educationSystem): bool
    {
        return $authUser->can('Update:EducationSystem');
    }

    public function delete(AuthUser $authUser, EducationSystem $educationSystem): bool
    {
        return $authUser->can('Delete:EducationSystem');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:EducationSystem');
    }

    public function restore(AuthUser $authUser, EducationSystem $educationSystem): bool
    {
        return $authUser->can('Restore:EducationSystem');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:EducationSystem');
    }

    public function forceDelete(AuthUser $authUser, EducationSystem $educationSystem): bool
    {
        return $authUser->can('ForceDelete:EducationSystem');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:EducationSystem');
    }

    public function replicate(AuthUser $authUser, EducationSystem $educationSystem): bool
    {
        return $authUser->can('Replicate:EducationSystem');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:EducationSystem');
    }
}
