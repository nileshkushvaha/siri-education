<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorCurriculumEligibility;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Mirrors InstructorSubjectTopicPolicy/CurriculumPolicy — administrative
 * academic-capability management, seeded via AcademicPermissionSeeder's
 * MODULES array (manager role gets the standard CRUD set;
 * super_admin bypasses via Gate::before). An instructor/student user
 * has none of these permissions by default, so they can neither
 * approve themselves nor mutate another instructor's eligibility.
 */
class InstructorCurriculumEligibilityPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InstructorCurriculumEligibility');
    }

    public function view(AuthUser $authUser, InstructorCurriculumEligibility $eligibility): bool
    {
        return $authUser->can('View:InstructorCurriculumEligibility');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InstructorCurriculumEligibility');
    }

    public function update(AuthUser $authUser, InstructorCurriculumEligibility $eligibility): bool
    {
        return $authUser->can('Update:InstructorCurriculumEligibility');
    }

    public function delete(AuthUser $authUser, InstructorCurriculumEligibility $eligibility): bool
    {
        return $authUser->can('Delete:InstructorCurriculumEligibility');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InstructorCurriculumEligibility');
    }

    public function restore(AuthUser $authUser, InstructorCurriculumEligibility $eligibility): bool
    {
        return $authUser->can('Restore:InstructorCurriculumEligibility');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InstructorCurriculumEligibility');
    }

    public function forceDelete(AuthUser $authUser, InstructorCurriculumEligibility $eligibility): bool
    {
        return $authUser->can('ForceDelete:InstructorCurriculumEligibility');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InstructorCurriculumEligibility');
    }

    public function replicate(AuthUser $authUser, InstructorCurriculumEligibility $eligibility): bool
    {
        return $authUser->can('Replicate:InstructorCurriculumEligibility');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InstructorCurriculumEligibility');
    }
}
