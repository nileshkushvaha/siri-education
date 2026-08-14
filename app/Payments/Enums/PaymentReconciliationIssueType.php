<?php

declare(strict_types=1);

namespace App\Payments\Enums;

/**
 * Phase 4E.2 — the discrepancy classes a VERIFIED provider event can
 * genuinely produce on the generic payment path.
 *
 * Deliberately only two. The audit's rule was "do not create
 * speculative issue types", so each case here corresponds to an
 * existing refusal branch in PackagePurchaseSettlementService rather
 * than to something that might one day happen:
 *
 *  - `amount_mismatch`   validateAmountAndCurrency()'s amount branch
 *  - `currency_mismatch` its currency branch
 *
 * Cases deliberately NOT modelled, and why:
 *  - `unknown_purchase`      the webhook controller has already
 *                            returned "ignored" for an unknown
 *                            reference; there is no payment attempt to
 *                            hang an issue off, and inventing one from
 *                            an unrecognised payload is exactly the
 *                            "never create records from a webhook"
 *                            rule.
 *  - `provider_mismatch`     unreachable after lookup: findByProviderReference()
 *                            scopes every query by provider, so an
 *                            attempt cannot be resolved by the wrong one.
 *  - `payable_mismatch`      the controller refuses non-package
 *                            payables before settlement is entered.
 *  - `attempt_already_closed` a genuine business outcome (a late event
 *                            about an attempt we already closed), not a
 *                            money discrepancy needing an operator.
 */
enum PaymentReconciliationIssueType: string
{
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::AmountMismatch => 'Amount Mismatch',
            self::CurrencyMismatch => 'Currency Mismatch',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AmountMismatch => 'danger',
            self::CurrencyMismatch => 'danger',
        };
    }

    /** Operator-facing explanation of what the platform actually did. */
    public function description(): string
    {
        return match ($this) {
            self::AmountMismatch => 'The provider reported a different amount than the approved purchase. No lessons were granted.',
            self::CurrencyMismatch => 'The provider reported a different currency than the approved purchase. No lessons were granted.',
        };
    }
}
