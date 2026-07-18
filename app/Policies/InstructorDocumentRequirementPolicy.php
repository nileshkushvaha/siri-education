<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorDocumentRequirement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Deliberately has no delete/deleteAny/forceDelete/restore methods —
 * InstructorDocumentRequirement can never be deleted
 * (PreventsHardDeletion); the only permissions seeded for this module
 * are ViewAny/View/Create/Update (see
 * InstructorDocumentRequirementPermissionSeeder).
 */
class InstructorDocumentRequirementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InstructorDocumentRequirement');
    }

    public function view(AuthUser $authUser, InstructorDocumentRequirement $requirement): bool
    {
        return $authUser->can('View:InstructorDocumentRequirement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InstructorDocumentRequirement');
    }

    public function update(AuthUser $authUser, InstructorDocumentRequirement $requirement): bool
    {
        return $authUser->can('Update:InstructorDocumentRequirement');
    }
}
