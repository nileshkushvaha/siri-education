<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AcademicLevel;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AcademicLevelPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AcademicLevel');
    }

    public function view(AuthUser $authUser, AcademicLevel $academicLevel): bool
    {
        return $authUser->can('View:AcademicLevel');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AcademicLevel');
    }

    public function update(AuthUser $authUser, AcademicLevel $academicLevel): bool
    {
        return $authUser->can('Update:AcademicLevel');
    }

    public function delete(AuthUser $authUser, AcademicLevel $academicLevel): bool
    {
        return $authUser->can('Delete:AcademicLevel');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AcademicLevel');
    }

    public function restore(AuthUser $authUser, AcademicLevel $academicLevel): bool
    {
        return $authUser->can('Restore:AcademicLevel');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AcademicLevel');
    }

    public function forceDelete(AuthUser $authUser, AcademicLevel $academicLevel): bool
    {
        return $authUser->can('ForceDelete:AcademicLevel');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AcademicLevel');
    }

    public function replicate(AuthUser $authUser, AcademicLevel $academicLevel): bool
    {
        return $authUser->can('Replicate:AcademicLevel');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AcademicLevel');
    }
}
