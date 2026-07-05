<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SkillLevel;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SkillLevelPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SkillLevel');
    }

    public function view(AuthUser $authUser, SkillLevel $skillLevel): bool
    {
        return $authUser->can('View:SkillLevel');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SkillLevel');
    }

    public function update(AuthUser $authUser, SkillLevel $skillLevel): bool
    {
        return $authUser->can('Update:SkillLevel');
    }

    public function delete(AuthUser $authUser, SkillLevel $skillLevel): bool
    {
        return $authUser->can('Delete:SkillLevel');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SkillLevel');
    }

    public function restore(AuthUser $authUser, SkillLevel $skillLevel): bool
    {
        return $authUser->can('Restore:SkillLevel');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SkillLevel');
    }

    public function forceDelete(AuthUser $authUser, SkillLevel $skillLevel): bool
    {
        return $authUser->can('ForceDelete:SkillLevel');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SkillLevel');
    }

    public function replicate(AuthUser $authUser, SkillLevel $skillLevel): bool
    {
        return $authUser->can('Replicate:SkillLevel');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SkillLevel');
    }
}
