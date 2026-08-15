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

    /**
     * What we know about the MONEY. See the generic queue's equivalent —
     * same question, same vocabulary, so an operator moving between the
     * two queues does not have to relearn what a word means.
     */
    public function moneyState(): string
    {
        return match ($this) {
            self::ProviderSuccessLocalIncomplete,
            self::LateSuccessResolutionFailed => 'Confirmed',
            // A refund obligation exists, so the money is ours and owed back.
            self::WalletCreditFailed => 'Owed to student',
            self::AmountMismatch, self::CurrencyMismatch => 'Disputed',
            self::ProviderUnavailable => 'Not yet confirmed',
            self::UnknownPaymentOutcome, self::StaleProcessing => 'Unresolved',
            default => 'Unknown',
        };
    }

    /** What the student actually got, or is still waiting for. */
    public function deliveryState(): string
    {
        return match ($this) {
            self::ProviderSuccessLocalIncomplete => 'Booking settlement NOT completed',
            self::LateSuccessResolutionFailed => 'Student recovery NOT completed',
            self::WalletCreditFailed => 'Wallet credit FAILED',
            self::AmountMismatch, self::CurrencyMismatch => 'Settlement refused',
            default => 'Booking not settled',
        };
    }

    public function moneyStateColor(): string
    {
        return match ($this) {
            self::ProviderSuccessLocalIncomplete,
            self::LateSuccessResolutionFailed,
            self::WalletCreditFailed => 'danger',
            self::AmountMismatch, self::CurrencyMismatch => 'warning',
            default => 'gray',
        };
    }

    /** Product-language explanation — never the raw enum value. */
    public function description(): string
    {
        return match ($this) {
            self::ProviderSuccessLocalIncomplete => 'The provider confirmed this payment but the booking was never financially settled. The student has paid and has no confirmed lesson.',
            self::LateSuccessResolutionFailed => 'A payment arrived after this booking ended and could not be credited to the student\'s wallet. The platform is holding their money.',
            self::WalletCreditFailed => 'A cancellation refund was approved but the wallet credit failed. The student is owed money that has not reached them.',
            self::AmountMismatch => 'The provider reported a different amount than this booking expects. Settlement was refused.',
            self::CurrencyMismatch => 'The provider reported a different currency than this booking expects. Settlement was refused.',
            self::ProviderUnavailable => 'The payment provider could not be reached to confirm this payment. Whether money was collected is still unknown; verification keeps retrying.',
            self::UnknownPaymentOutcome => 'Verification could not establish what the provider did with this payment.',
            self::StaleProcessing => 'This payment has been awaiting a provider outcome far longer than expected.',
            self::UnknownPaymentReference => 'Historical record: a provider reference that matched no known booking payment.',
            self::DuplicateProviderReference => 'Historical record: a provider reference seen more than once.',
            self::LocalSuccessProviderMismatch => 'Historical record: a locally captured payment the provider later described differently.',
            self::RefundStatusMismatch => 'Historical record: local and provider refund state disagreed.',
        };
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
