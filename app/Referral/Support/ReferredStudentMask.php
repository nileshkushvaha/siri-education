<?php

declare(strict_types=1);

namespace App\Referral\Support;

use App\Models\User;

/**
 * The single masked-identity rule for referred students (SRS 16.27):
 * first name plus last initial, never email, phone, or full name.
 * Every referral surface (notifications, student history) uses this —
 * no surface invents its own masking.
 */
final class ReferredStudentMask
{
    public static function mask(?User $referredStudent): string
    {
        if ($referredStudent === null) {
            return 'A student';
        }

        $first = trim((string) $referredStudent->first_name);

        if ($first === '') {
            return 'A student';
        }

        $lastInitial = mb_substr(trim((string) $referredStudent->last_name), 0, 1);

        return $lastInitial === '' ? $first : sprintf('%s %s.', $first, mb_strtoupper($lastInitial));
    }
}
