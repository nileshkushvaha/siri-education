<?php

declare(strict_types=1);

namespace App\Support\Financial;

use App\Models\Currency;
use App\Support\Financial\Exceptions\CurrencyNotUsableException;

/**
 * Phase 24M — GAP-031 (SRS-21-4, SRS §21.38/§21.40): the single
 * authority on whether a currency code may be used for a given
 * financial operation. Centralizes what would otherwise be scattered
 * `Currency::where('status', 'active')` checks across Booking, Wallet,
 * and Referral services.
 *
 * The active flag controls NEW use only:
 *  - NewInitiation requires an existing, non-soft-deleted, Active
 *    currency row — anything else throws the generic
 *    CurrencyNotUsableException (never a raw provider/config detail).
 *  - Every other intent (ExistingSettlement, ProtectiveCredit,
 *    AdministrativeCorrection) resolves the currency permissively
 *    (including inactive and soft-deleted rows) — an obligation that
 *    already exists must remain settleable/refundable/reversible even
 *    after an admin disables the currency it was created in. A code
 *    that never existed at all still throws, since that indicates a
 *    genuine data-integrity problem rather than a later deactivation.
 */
final class CurrencyEligibilityPolicy
{
    /**
     * @param  bool  $lock  Phase 24M Step 9: lock the Currency row for
     *                      the duration of the caller's transaction —
     *                      only meaningful for NewInitiation, so an
     *                      admin disabling the currency concurrently is
     *                      serialized against this decision rather than
     *                      racing it. Never held across a network call;
     *                      callers must commit before contacting a provider.
     *
     * @throws CurrencyNotUsableException
     */
    public function assertUsable(string $currencyCode, FinancialOperation $operation, bool $lock = false): Currency
    {
        $code = self::normalize($currencyCode);

        if ($operation === FinancialOperation::NewInitiation) {
            $query = Currency::query()->where('code', $code);

            if ($lock) {
                $query->lockForUpdate();
            }

            $currency = $query->first();

            if ($currency === null || $currency->status !== 'active') {
                throw new CurrencyNotUsableException;
            }

            return $currency;
        }

        $currency = Currency::withTrashed()->where('code', $code)->first();

        if ($currency === null) {
            throw new CurrencyNotUsableException;
        }

        return $currency;
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
