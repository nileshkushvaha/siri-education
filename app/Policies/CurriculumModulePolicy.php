<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CurriculumModule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CurriculumModulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CurriculumModule');
    }

    public function view(AuthUser $authUser, CurriculumModule $module): bool
    {
        return $authUser->can('View:CurriculumModule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CurriculumModule');
    }

    public function update(AuthUser $authUser, CurriculumModule $module): bool
    {
        return $authUser->can('Update:CurriculumModule');
    }

    public function delete(AuthUser $authUser, CurriculumModule $module): bool
    {
        return $authUser->can('Delete:CurriculumModule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CurriculumModule');
    }

    public function restore(AuthUser $authUser, CurriculumModule $module): bool
    {
        return $authUser->can('Restore:CurriculumModule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CurriculumModule');
    }

    public function forceDelete(AuthUser $authUser, CurriculumModule $module): bool
    {
        return $authUser->can('ForceDelete:CurriculumModule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CurriculumModule');
    }

    public function replicate(AuthUser $authUser, CurriculumModule $module): bool
    {
        return $authUser->can('Replicate:CurriculumModule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CurriculumModule');
    }
}
