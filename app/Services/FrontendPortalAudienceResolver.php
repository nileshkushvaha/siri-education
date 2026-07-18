<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalAudience;
use App\Models\User;

/** Resolves the active audience inside the Frontend Portal. */
final class FrontendPortalAudienceResolver
{
    public function __construct(private readonly PortalResolver $portals) {}

    public function resolve(User $user): PortalAudience
    {
        if ($user->hasRole('student')) {
            return PortalAudience::Student;
        }

        if ($user->hasRole('instructor')) {
            return PortalAudience::Instructor;
        }

        // Preserve the established frontend fallback for legacy/future roles.
        return $this->portals->usesAdminPortal($user)
            ? PortalAudience::AdminOrUnsupported
            : PortalAudience::Student;
    }
}
