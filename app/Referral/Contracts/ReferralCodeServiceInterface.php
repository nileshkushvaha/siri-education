<?php

declare(strict_types=1);

namespace App\Referral\Contracts;

use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Exceptions\ReferralException;

interface ReferralCodeServiceInterface
{
    /**
     * Find or create the student's referral code. Idempotent — repeated
     * calls always return the same row, including a disabled one (a
     * disabled code is never silently replaced).
     *
     * @throws ReferralException when the user is not a student, or generation exhausts its collision retries
     */
    public function getOrCreateForStudent(User $user): ReferralCode;

    /**
     * Case-insensitive lookup of an ACTIVE code. Returns null for
     * unknown, malformed, or disabled codes — never reveals which.
     */
    public function findActiveByCode(?string $rawCode): ?ReferralCode;

    /**
     * Disable a code (abuse response). Requires the DisableReferralCodes
     * permission and a reason; audit-logged as an admin override.
     */
    public function disable(ReferralCode $code, User $actor, string $reason): ReferralCode;
}
