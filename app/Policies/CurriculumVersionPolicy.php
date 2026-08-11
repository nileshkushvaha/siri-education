<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CurriculumVersion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CurriculumVersionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CurriculumVersion');
    }

    public function view(AuthUser $authUser, CurriculumVersion $version): bool
    {
        return $authUser->can('View:CurriculumVersion');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CurriculumVersion');
    }

    public function update(AuthUser $authUser, CurriculumVersion $version): bool
    {
        return $authUser->can('Update:CurriculumVersion');
    }

    public function delete(AuthUser $authUser, CurriculumVersion $version): bool
    {
        return $authUser->can('Delete:CurriculumVersion');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CurriculumVersion');
    }

    public function restore(AuthUser $authUser, CurriculumVersion $version): bool
    {
        return $authUser->can('Restore:CurriculumVersion');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CurriculumVersion');
    }

    public function forceDelete(AuthUser $authUser, CurriculumVersion $version): bool
    {
        return $authUser->can('ForceDelete:CurriculumVersion');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CurriculumVersion');
    }

    public function replicate(AuthUser $authUser, CurriculumVersion $version): bool
    {
        return $authUser->can('Replicate:CurriculumVersion');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CurriculumVersion');
    }
}
