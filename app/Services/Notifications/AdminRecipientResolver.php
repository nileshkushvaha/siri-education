<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Resolves active administrator recipients for one permission — the
 * exact permission each queue widget already gates its own
 * `canView()` on (`ViewReviewModerationQueue`, `ViewReviewReports`,
 * `ViewInstructorQualityAlerts`), so "who can act on this" and "who
 * gets notified about this" can never drift apart. `super_admin`s are
 * always included (they bypass every permission check via
 * `Gate::before()`, so excluding them here would silently under-notify
 * the one role that can always act), deduplicated with anyone who
 * also holds the permission directly. Never notifies every
 * administrator-like user indiscriminately.
 */
final class AdminRecipientResolver
{
    /** @return Collection<int, User> */
    public function forPermission(string $permission): Collection
    {
        $withPermission = $this->usersWithPermission($permission);
        $superAdmins = $this->superAdmins();

        return $withPermission->merge($superAdmins)->unique('id')->values();
    }

    /** @return Collection<int, User> */
    private function usersWithPermission(string $permission): Collection
    {
        try {
            return User::permission($permission)
                ->where('status', User::STATUS_ACTIVE)
                ->get();
        } catch (PermissionDoesNotExist) {
            return new Collection;
        }
    }

    /** @return Collection<int, User> */
    private function superAdmins(): Collection
    {
        try {
            return User::role('super_admin')
                ->where('status', User::STATUS_ACTIVE)
                ->get();
        } catch (RoleDoesNotExist) {
            return new Collection;
        }
    }
}
