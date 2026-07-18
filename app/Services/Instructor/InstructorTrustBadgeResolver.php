<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Enums\InstructorStatus;
use App\Models\User;

/**
 * Single source of truth for "does this instructor show a Verified
 * badge publicly?" (Phase 23F). Deliberately returns a single boolean,
 * not a set of independent badges — "Identity Verified" /
 * "Qualification Verified" are explicitly out of scope per the SRS
 * until a real verification pipeline for each exists; adding separate
 * booleans now would be exactly the boolean-explosion this class exists
 * to avoid.
 */
final class InstructorTrustBadgeResolver
{
    public const LABEL = 'Verified Instructor';

    public function isVerified(User $instructor): bool
    {
        $profile = $instructor->profile;

        return $profile !== null
            && in_array($profile->instructor_status, InstructorStatus::bookable(), true)
            && (bool) $profile->is_instructor_verified;
    }
}
