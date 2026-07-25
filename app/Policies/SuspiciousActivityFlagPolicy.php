<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SuspiciousActivityFlag;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Compliance-flag inspection/review authorization — staff only. Every
 * review action additionally denies the subject the flag is about,
 * even if they somehow held the permission — mirrors
 * InstructorQualityAlertPolicy's identical self-review exclusion
 * (also independently enforced in ComplianceMonitoringService, since
 * Gate::before's super_admin bypass skips this policy entirely).
 */
class SuspiciousActivityFlagPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:SuspiciousActivityFlag');
    }

    public function view(User $user, SuspiciousActivityFlag $flag): bool
    {
        return $this->hasPermission($user, 'View:SuspiciousActivityFlag');
    }

    public function beginReview(User $user, SuspiciousActivityFlag $flag): bool
    {
        return $user->id !== $flag->subject_id
            && $this->hasPermission($user, 'BeginReview:SuspiciousActivityFlag');
    }

    public function resolve(User $user, SuspiciousActivityFlag $flag): bool
    {
        return $user->id !== $flag->subject_id
            && $this->hasPermission($user, 'Resolve:SuspiciousActivityFlag');
    }

    public function dismiss(User $user, SuspiciousActivityFlag $flag): bool
    {
        return $user->id !== $flag->subject_id
            && $this->hasPermission($user, 'Dismiss:SuspiciousActivityFlag');
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
