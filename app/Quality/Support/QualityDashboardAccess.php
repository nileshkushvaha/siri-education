<?php

declare(strict_types=1);

namespace App\Quality\Support;

use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Shared permission check for the Reviews & Quality dashboard page and
 * its section widgets — mirrors `CacheManagerPage`'s exact
 * super-admin-bypass + `hasPermissionTo()` pattern, the established
 * convention for this kind of operational (not CRUD-resource)
 * Filament page. Extracted here since the same check is needed by the
 * page itself and by every one of its section widgets.
 */
final class QualityDashboardAccess
{
    public static function userCan(string $permission): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
