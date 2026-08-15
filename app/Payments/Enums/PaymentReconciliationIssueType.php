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

    // ── PAY-1 (PAY-AUD-001) — operational collection failures ────────
    //
    // The two cases above only fire when a provider CONTRADICTS us. The
    // four below fire when a collection simply gets stuck, which is the
    // far more common production failure and which previously produced
    // nothing an operator could see: the sweep retried silently every
    // five minutes forever while the student waited.
    //
    // Each has a deterministic detection rule tied to observable state
    // (see PackagePurchaseReconciliationService) — none infers provider
    // intent, and none can settle or grant anything.

    /** Verification could not reach the provider at all (gateway error), past the grace window. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The provider CONFIRMED payment but local activation failed — money in, no access. */
    case SettlementFailed = 'settlement_failed';

    /** An open attempt has outlived the reconciliation window without any provider resolution. */
    case StaleProcessing = 'stale_processing';

    /** An attempt claimed initialization long ago but never recorded a provider reference. */
    case MissingProviderReference = 'missing_provider_reference';

    public function label(): string
    {
        return match ($this) {
            self::AmountMismatch => 'Amount Mismatch',
            self::CurrencyMismatch => 'Currency Mismatch',
            self::ProviderUnavailable => 'Provider Unavailable',
            self::SettlementFailed => 'Settlement Failed',
            self::StaleProcessing => 'Stale Processing',
            self::MissingProviderReference => 'Missing Provider Reference',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AmountMismatch => 'danger',
            self::CurrencyMismatch => 'danger',
            // Money is known to be collected with nothing delivered —
            // the most urgent state on this queue.
            self::SettlementFailed => 'danger',
            self::ProviderUnavailable => 'warning',
            self::StaleProcessing => 'warning',
            self::MissingProviderReference => 'warning',
        };
    }

    /**
     * Money is known to be with the provider while the customer has
     * nothing. Distinguished from the rest because it is the only state
     * on this queue where the platform is definitively in the student's
     * debt.
     */
    public function isMoneyCollectedWithoutDelivery(): bool
    {
        return $this === self::SettlementFailed;
    }

    /**
     * What we know about the MONEY, as a short operator-facing label.
     *
     * The single most important question when triaging a payment
     * incident is "is the money ours?" — an operator should not have to
     * infer it from an enum name. Kept deliberately blunt, and never
     * optimistic: anything short of provider confirmation reads as
     * unconfirmed.
     */
    public function moneyState(): string
    {
        return match ($this) {
            self::SettlementFailed => 'Confirmed',
            self::AmountMismatch, self::CurrencyMismatch => 'Disputed',
            self::ProviderUnavailable => 'Not yet confirmed',
            self::StaleProcessing, self::MissingProviderReference => 'Unresolved',
        };
    }

    /** What the customer actually received. */
    public function deliveryState(): string
    {
        return match ($this) {
            // The provider took the money and the package never activated.
            self::SettlementFailed => 'Package access NOT completed',
            self::AmountMismatch, self::CurrencyMismatch => 'Settlement refused',
            default => 'Nothing granted',
        };
    }

    public function moneyStateColor(): string
    {
        return match ($this) {
            self::SettlementFailed => 'danger',
            self::AmountMismatch, self::CurrencyMismatch => 'warning',
            default => 'gray',
        };
    }

    /** Operator-facing explanation of what the platform actually did. */
    public function description(): string
    {
        return match ($this) {
            self::AmountMismatch => 'The provider reported a different amount than the approved purchase. No lessons were granted.',
            self::CurrencyMismatch => 'The provider reported a different currency than the approved purchase. No lessons were granted.',
            self::ProviderUnavailable => 'The payment provider could not be reached to confirm this payment. Whether money was collected is still unknown; reconciliation keeps retrying.',
            self::SettlementFailed => 'The provider confirmed this payment but the package could not be activated. The student has paid and has no lessons — investigate before anything else on this queue.',
            self::StaleProcessing => 'This payment has been awaiting a provider outcome far longer than expected. No money has been recognised and no lessons were granted.',
            self::MissingProviderReference => 'Checkout began but no provider reference was ever recorded, so this payment cannot be verified automatically. No lessons were granted.',
        };
    }
}
