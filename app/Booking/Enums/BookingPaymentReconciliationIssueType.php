<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** The exact 12 issue types Phase 16C specifies — own enum, never shared with the payout domain's PayoutReconciliationIssueType. */
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
