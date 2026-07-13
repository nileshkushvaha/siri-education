<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReviewReport;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Report inspection/resolution authorization — staff only. `resolve`
 * additionally denies the instructor being reviewed, even if they
 * somehow held the permission: an instructor must never be able to
 * resolve a report about their own review. Students never hold
 * `Resolve:ReviewReport` at all, so they're denied structurally by the
 * permission check alone — the instructor exclusion needs the extra
 * identity check because an instructor could plausibly be granted
 * staff permissions in a future role change.
 */
class ReviewReportPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:ReviewReport');
    }

    public function view(User $user, ReviewReport $report): bool
    {
        return $this->hasPermission($user, 'View:ReviewReport');
    }

    public function resolve(User $user, ReviewReport $report): bool
    {
        return $user->id !== $report->review->instructor_id
            && $this->hasPermission($user, 'Resolve:ReviewReport');
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
