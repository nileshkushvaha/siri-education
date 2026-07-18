<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalAudience;
use App\Models\User;

/** Resolves the active audience inside the Frontend Portal. */
final class FrontendPortalAudienceResolver
{
    public function __construct(
        private readonly PortalResolver $portals,
        private readonly FrontendPortalWorkspaceService $workspaces,
    ) {}

    public function resolve(User $user): PortalAudience
    {
        $hasStudentRole = $user->hasRole('student');
        $hasInstructorRole = $user->hasRole('instructor');

        // Only a genuinely dual-role user can have a meaningful workspace
        // preference; a single-role user's audience is never ambiguous, so
        // the established behavior below is left untouched for them.
        if ($hasStudentRole && $hasInstructorRole) {
            $preferred = $this->workspaces->preferredAudience($user);

            if ($preferred !== null) {
                return $preferred;
            }
        }

        if ($hasStudentRole) {
            return PortalAudience::Student;
        }

        if ($hasInstructorRole) {
            return PortalAudience::Instructor;
        }

        // Preserve the established frontend fallback for legacy/future roles.
        return $this->portals->usesAdminPortal($user)
            ? PortalAudience::AdminOrUnsupported
            : PortalAudience::Student;
    }
}
