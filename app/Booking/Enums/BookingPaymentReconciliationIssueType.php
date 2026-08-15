<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** The exact 12 issue types this domain specifies — own enum, never shared with the payout domain's PayoutReconciliationIssueType. */
enum BookingPaymentReconciliationIssueType: string
{
    case UnknownPaymentOutcome = 'unknown_payment_outcome';
    case ProviderSuccessLocalIncomplete = 'provider_success_local_incomplete';
    case LocalSuccessProviderMismatch = 'local_success_provider_mismatch';
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case UnknownPaymentReference = 'unknown_payment_reference';
    case DuplicateProviderReference = 'duplicate_provider_reference';
    case StaleProcessing = 'stale_processing';
    case ProviderUnavailable = 'provider_unavailable';
    case LateSuccessResolutionFailed = 'late_success_resolution_failed';
    case WalletCreditFailed = 'wallet_credit_failed';
    case RefundStatusMismatch = 'refund_status_mismatch';

    /**
     * Can the current architecture actually produce this incident?
     *
     * The queue used to advertise all twelve cases in its filter while
     * only two had a producer, so an operator could filter for states
     * the platform is incapable of generating and conclude, wrongly,
     * that nothing was wrong.
     *
     * Cases marked false are retained rather than deleted: rows may
     * exist in production that this environment cannot see, and a
     * removed case would make them impossible to hydrate. They stay
     * readable and stay out of the active filter vocabulary.
     */
    public function isLive(): bool
    {
        return match ($this) {
            // Verified provider outcome the platform could not trust.
            self::UnknownPaymentOutcome,
            self::ProviderUnavailable,
            self::AmountMismatch,
            self::CurrencyMismatch,
            self::StaleProcessing,
            // Money is ours, the customer has nothing.
            self::ProviderSuccessLocalIncomplete,
            self::LateSuccessResolutionFailed,
            self::WalletCreditFailed => true,

            // Structurally unsupported: the issue table requires a
            // booking_payment_id (NOT NULL + FK), so a provider
            // reference that matches no BookingPayment has nothing to
            // attach to. Belongs to a future provider-level unmatched
            // payment queue, not this one.
            self::UnknownPaymentReference,

            // Already owned by database uniqueness on provider_order_id,
            // provider_payment_id and idempotency_key. Runtime detection
            // would duplicate an invariant the schema enforces.
            self::DuplicateProviderReference,

            // Unobservable: reconciliationDue() deliberately excludes
            // Captured, so no path re-polls settled money. Making it
            // observable would mean re-verifying captured payments and
            // acting on a single contrary provider response — a
            // financial reversal that needs far stronger evidence and an
            // approved policy.
            self::LocalSuccessProviderMismatch,

            // Provider refunds are synchronous (refundViaProvider):
            // success writes the resolution, failure clears the claim and
            // throws. Nothing polls a refund afterwards, so local and
            // provider refund state never drift apart to be compared.
            self::RefundStatusMismatch => false,
        };
    }

    /** Types the operator filter offers — never a state the platform cannot generate. */
    public static function live(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isLive()));
    }

    public function label(): string
    {
        return match ($this) {
            self::UnknownPaymentOutcome => 'Unknown payment outcome',
            self::ProviderSuccessLocalIncomplete => 'Provider succeeded, local incomplete',
            self::LocalSuccessProviderMismatch => 'Local success, provider mismatch',
            self::AmountMismatch => 'Amount mismatch',
            self::CurrencyMismatch => 'Currency mismatch',
            self::UnknownPaymentReference => 'Unknown payment reference',
            self::DuplicateProviderReference => 'Duplicate provider reference',
            self::StaleProcessing => 'Stale processing attempt',
            self::ProviderUnavailable => 'Provider unavailable',
            self::LateSuccessResolutionFailed => 'Late success resolution failed',
            self::WalletCreditFailed => 'Wallet credit failed',
            self::RefundStatusMismatch => 'Refund status mismatch',
        };
    }
}
