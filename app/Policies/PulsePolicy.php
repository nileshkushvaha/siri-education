<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Phase 24O — GAP-033: authorization for Laravel Pulse's dashboard.
 * `view()` backs the package's own fixed `viewPulse` Gate ability (see
 * AppServiceProvider::boot()) — mirrors QueueMonitorPolicy/SchedulerMonitorPolicy's
 * shape exactly, so Pulse gets the same operational-admin-only access
 * model as every other system-health surface.
 */
class PulsePolicy
{
    use HandlesAuthorization;

    public function view(AuthUser $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        try {
            return method_exists($user, 'hasPermissionTo')
                && $user->hasPermissionTo('pulse.view');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function isSuperAdmin(AuthUser $user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
