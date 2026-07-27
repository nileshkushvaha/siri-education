<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstructorStatus;
use App\Enums\PortalAudience;
use App\Models\User;

/**
 * Backend-only foundation for dual-role workspace switching.
 * Owns two things, and only these two: which workspaces a user is
 * currently allowed to occupy, and the session-stored preference between
 * them. It does not decide the resolved audience for a request — that
 * remains FrontendPortalAudienceResolver's job; this service is one of
 * its inputs, never a second source of truth.
 *
 * No route or UI reads/writes the session key yet.
 * selectWorkspace() exists so a future
 * controller has a single, already-validated place to call.
 */
final class FrontendPortalWorkspaceService
{
    public const SESSION_KEY = 'frontend_portal_audience';

    /** Instructor-side statuses that may use the instructor workspace. Mirrors the reviewed, non-terminal states — not just bookable(). */
    private const INSTRUCTOR_WORKSPACE_STATUSES = [
        InstructorStatus::Approved,
        InstructorStatus::Active,
        InstructorStatus::Vacation,
    ];

    public function canAccessStudentWorkspace(User $user): bool
    {
        return $user->hasRole('student');
    }

    public function canAccessInstructorWorkspace(User $user): bool
    {
        if (! $user->hasRole('instructor')) {
            return false;
        }

        $status = $user->profile?->instructor_status;

        return $status !== null && in_array($status, self::INSTRUCTOR_WORKSPACE_STATUSES, true);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function availableWorkspaces(User $user): array
    {
        $workspaces = [];

        if ($this->canAccessStudentWorkspace($user)) {
            $workspaces[] = ['key' => PortalAudience::Student->value, 'label' => 'Student'];
        }

        if ($this->canAccessInstructorWorkspace($user)) {
            $workspaces[] = ['key' => PortalAudience::Instructor->value, 'label' => 'Instructor'];
        }

        return $workspaces;
    }

    /**
     * The session-stored workspace preference, honored only if the user is
     * still actually eligible for it right now. A stale, spoofed, or
     * no-longer-eligible preference (e.g. session says "instructor" but
     * the application was since rejected) is silently ignored — the
     * caller falls back to its own default.
     */
    public function preferredAudience(User $user): ?PortalAudience
    {
        $stored = session(self::SESSION_KEY);

        if (! is_string($stored)) {
            return null;
        }

        $audience = PortalAudience::tryFrom($stored);

        return match ($audience) {
            PortalAudience::Student => $this->canAccessStudentWorkspace($user) ? $audience : null,
            PortalAudience::Instructor => $this->canAccessInstructorWorkspace($user) ? $audience : null,
            PortalAudience::AdminOrUnsupported, null => null,
        };
    }

    /** @return bool true if the switch was accepted and stored, false if the user cannot access that workspace. */
    public function selectWorkspace(User $user, PortalAudience $audience): bool
    {
        $allowed = match ($audience) {
            PortalAudience::Student => $this->canAccessStudentWorkspace($user),
            PortalAudience::Instructor => $this->canAccessInstructorWorkspace($user),
            PortalAudience::AdminOrUnsupported => false,
        };

        if (! $allowed) {
            return false;
        }

        session([self::SESSION_KEY => $audience->value]);

        return true;
    }

    public function clearPreference(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
