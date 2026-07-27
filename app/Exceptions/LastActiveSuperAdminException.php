<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * SRS-23-7: thrown by SuperAdminGuardService when a
 * mutation would leave the platform with zero active, access-capable
 * Super Admins. No role IDs, permission details, or the current count
 * are exposed — callers (Filament pages/actions) render getMessage()
 * verbatim to the acting administrator.
 */
final class LastActiveSuperAdminException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'You cannot remove access from the last active Super Admin. Assign or activate another Super Admin first.',
        );
    }
}
