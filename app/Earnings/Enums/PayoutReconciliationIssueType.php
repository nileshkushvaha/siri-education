<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

enum PayoutReconciliationIssueType: string
{
    case UnknownProviderOutcome = 'unknown_provider_outcome';
    case LocalProviderStatusMismatch = 'local_provider_status_mismatch';
    case ProviderSuccessLocalIncomplete = 'provider_success_local_incomplete';
    case LocalSuccessProviderMissing = 'local_success_provider_missing';
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case UnknownProviderReference = 'unknown_provider_reference';
    case DuplicateProviderReference = 'duplicate_provider_reference';
    case ReversedPayout = 'reversed_payout';
    case StaleProcessing = 'stale_processing';
    case ProviderUnavailable = 'provider_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::UnknownProviderOutcome => 'Unknown provider outcome',
            self::LocalProviderStatusMismatch => 'Local/provider status mismatch',
            self::ProviderSuccessLocalIncomplete => 'Provider succeeded, local incomplete',
            self::LocalSuccessProviderMissing => 'Local success, provider record missing',
            self::AmountMismatch => 'Amount mismatch',
            self::CurrencyMismatch => 'Currency mismatch',
            self::UnknownProviderReference => 'Unknown provider reference',
            self::DuplicateProviderReference => 'Duplicate provider reference',
            self::ReversedPayout => 'Reversed payout',
            self::StaleProcessing => 'Stale processing attempt',
            self::ProviderUnavailable => 'Provider unavailable',
        };
    }
}
