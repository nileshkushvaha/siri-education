<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Student-side lifecycle — separate from App\Models\User::status
 * (account/login eligibility) and App\Enums\InstructorStatus
 * (instructor application/professional lifecycle). Lives on
 * UserProfile::student_status, nullable — set to Registered when the
 * user is assigned the 'student' role at registration; null for users
 * who never held the student role.
 */
enum StudentStatus: string
{
    case Registered = 'registered';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Registered => 'info',
            self::Active => 'success',
            self::Suspended => 'danger',
            self::Archived => 'gray',
        };
    }

    /**
     * Phase 24H transition matrix. The SRS states the four states and
     * that suspension/archival exist, but never spells out a transition
     * matrix (unlike Instructor/Admin, which get explicit lifecycle
     * diagrams) — this is deliberately the SAFER, MORE RESTRICTIVE
     * reading where the SRS is silent:
     *
     * - Registered can go to any of Active/Suspended/Archived (an
     *   unverified/inactive account can still be administratively
     *   restricted).
     * - Active <-> Suspended is reversible, per this phase's own
     *   "suspension is a reversible administrative restriction" default.
     * - Active/Suspended -> Archived is one-way. Archived is treated as
     *   TERMINAL — no transition back to Active or Suspended — mirroring
     *   the existing, already-shipped InstructorStatus::archive()
     *   precedent ("Terminal — an archived instructor never re-enters
     *   the lifecycle through this service"). The SRS's only hint that
     *   anything is ever "restored" (§6.22 "Account restoration", §17.9
     *   "Account restored") is generic and never confirms this applies
     *   to Archived specifically — restoring archived-student access is
     *   a genuine product decision this phase does not make unilaterally.
     *
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        'registered' => ['active', 'suspended', 'archived'],
        'active' => ['suspended', 'archived'],
        'suspended' => ['active', 'archived'],
        'archived' => [],
    ];

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return in_array($target->value, self::TRANSITIONS[$this->value], true);
    }

    /** Suspended/Archived students lose ordinary student access (SRS: "Suspended or archived accounts shall be prevented from authenticating"). */
    public function blocksAccess(): bool
    {
        return in_array($this, [self::Suspended, self::Archived], true);
    }
}
