<?php

declare(strict_types=1);

namespace App\Earnings\Support;

use App\Earnings\Exceptions\EarningException;

/**
 * The single place hourly compensation amounts are computed.
 * Integer arithmetic only — no PHP float ever touches a canonical
 * amount. Rounding policy (documented, tested, stored in every earning
 * snapshot): ROUND HALF UP to the nearest minor unit.
 *
 *   amount = round_half_up(rate_minor × eligible_minutes / 60)
 *   e.g. 1001 × 45 / 60 = 750.75 → 751
 */
final class CompensationMath
{
    public const string ROUNDING_POLICY = 'half_up_minor';

    public static function hourlyAmount(int $rateMinor, int $eligibleMinutes): int
    {
        if ($rateMinor <= 0) {
            throw new EarningException('The hourly rate must be positive.');
        }

        if ($eligibleMinutes <= 0) {
            throw new EarningException('Eligible lesson minutes must be positive.');
        }

        // (rate × minutes + 30) intdiv 60 === round-half-up of the exact
        // rational result, entirely in integers.
        return intdiv($rateMinor * $eligibleMinutes + 30, 60);
    }
}
