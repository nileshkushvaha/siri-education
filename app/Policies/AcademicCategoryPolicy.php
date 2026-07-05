<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AcademicCategory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AcademicCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AcademicCategory');
    }

    public function view(AuthUser $authUser, AcademicCategory $academicCategory): bool
    {
        return $authUser->can('View:AcademicCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AcademicCategory');
    }

    public function update(AuthUser $authUser, AcademicCategory $academicCategory): bool
    {
        return $authUser->can('Update:AcademicCategory');
    }

    public function delete(AuthUser $authUser, AcademicCategory $academicCategory): bool
    {
        return $authUser->can('Delete:AcademicCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AcademicCategory');
    }

    public function restore(AuthUser $authUser, AcademicCategory $academicCategory): bool
    {
        return $authUser->can('Restore:AcademicCategory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AcademicCategory');
    }

    public function forceDelete(AuthUser $authUser, AcademicCategory $academicCategory): bool
    {
        return $authUser->can('ForceDelete:AcademicCategory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AcademicCategory');
    }

    public function replicate(AuthUser $authUser, AcademicCategory $academicCategory): bool
    {
        return $authUser->can('Replicate:AcademicCategory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AcademicCategory');
    }
}
