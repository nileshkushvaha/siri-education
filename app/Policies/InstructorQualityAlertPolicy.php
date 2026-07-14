<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstructorQualityAlert;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Quality-alert inspection/resolution authorization — staff only, no
 * instructor or student access at all (there is no public quality
 * score in this phase, and an instructor's own alerts are never
 * visible to them). `resolve` additionally denies the instructor the
 * alert is about, even if they somehow held the permission — mirrors
 * ReviewReportPolicy's identical self-resolution exclusion.
 */
class InstructorQualityAlertPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:InstructorQualityAlert');
    }

    public function view(User $user, InstructorQualityAlert $alert): bool
    {
        return $this->hasPermission($user, 'View:InstructorQualityAlert');
    }

    public function resolve(User $user, InstructorQualityAlert $alert): bool
    {
        return $user->id !== $alert->instructor_id
            && $this->hasPermission($user, 'Resolve:InstructorQualityAlert');
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
