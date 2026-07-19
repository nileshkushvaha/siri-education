<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase 24E — GAP-010/SRS-23-7: thrown when a Filament action attempts
 * to delete or incompatibly rename the canonical `super_admin` role.
 * Every authorization path in the app (Gate::before(), PortalResolver,
 * User::isSuperAdmin()) recognizes Super Admins by this exact role
 * NAME, not an ID — renaming or deleting it would silently strip
 * platform access from every Super Admin at once.
 */
final class CanonicalSuperAdminRoleProtectedException extends RuntimeException
{
    public static function forDeletion(): self
    {
        return new self('The "super_admin" role is required by the platform and cannot be deleted.');
    }

    public static function forRename(): self
    {
        return new self('The "super_admin" role name cannot be changed — the platform recognizes Super Admin access by this exact role name.');
    }
}
