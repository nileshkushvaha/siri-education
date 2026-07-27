<?php

declare(strict_types=1);

namespace App\Support\Financial;

/**
 * SRS-21-4, SRS §21.38/§21.40: the caller's
 * intent when asking CurrencyEligibilityPolicy whether a currency may
 * be used. Only NewInitiation is ever rejected for an inactive
 * currency — every other intent represents an obligation that already
 * exists and must remain processable regardless of the currency's
 * current status.
 */
enum FinancialOperation
{
    /** A brand-new booking price, payment attempt, provider order, or wallet creation. The only intent that requires Active status. */
    case NewInitiation;

    /** Settling an already-created payment attempt (webhook, verification/requery). Never re-checks Active status. */
    case ExistingSettlement;

    /** A refund, held-reward credit, or other credit owed for a prior event. Never re-checks Active status. */
    case ProtectiveCredit;

    /** An administrative correction, reversal, or reconciliation repair. Never re-checks Active status. */
    case AdministrativeCorrection;
}
