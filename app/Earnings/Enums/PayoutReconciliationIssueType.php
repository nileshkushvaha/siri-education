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

    // RazorpayX-specific (Phase 16B) — destination provisioning and
    // payout-status reconciliation issues that have no generic
    // equivalent above. Never created for any other provider.
    case RazorpayXContactProvisioningUnknown = 'razorpayx_contact_provisioning_unknown';
    case RazorpayXFundAccountProvisioningUnknown = 'razorpayx_fund_account_provisioning_unknown';
    case RazorpayXContactMismatch = 'razorpayx_contact_mismatch';
    case RazorpayXFundAccountMismatch = 'razorpayx_fund_account_mismatch';
    case RazorpayXPayoutStatusUnknown = 'razorpayx_payout_status_unknown';
    case RazorpayXUtrMissingAfterProcessed = 'razorpayx_utr_missing_after_processed';
    case RazorpayXUtrMismatch = 'razorpayx_utr_mismatch';
    case RazorpayXQueuedStale = 'razorpayx_queued_stale';
    case RazorpayXWebhookEventConflict = 'razorpayx_webhook_event_conflict';
    case RazorpayXIpAllowlistingRevoked = 'razorpayx_ip_allowlisting_revoked';
    case RazorpayXDuplicatePayoutReference = 'razorpayx_duplicate_payout_reference';

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
            self::RazorpayXContactProvisioningUnknown => 'RazorpayX Contact provisioning outcome unknown',
            self::RazorpayXFundAccountProvisioningUnknown => 'RazorpayX Fund Account provisioning outcome unknown',
            self::RazorpayXContactMismatch => 'RazorpayX Contact mismatch',
            self::RazorpayXFundAccountMismatch => 'RazorpayX Fund Account mismatch',
            self::RazorpayXPayoutStatusUnknown => 'RazorpayX payout status unknown',
            self::RazorpayXUtrMissingAfterProcessed => 'RazorpayX UTR missing after processed',
            self::RazorpayXUtrMismatch => 'RazorpayX UTR mismatch',
            self::RazorpayXQueuedStale => 'RazorpayX payout queued too long',
            self::RazorpayXWebhookEventConflict => 'RazorpayX webhook events conflict',
            self::RazorpayXIpAllowlistingRevoked => 'RazorpayX IP allowlisting appears revoked',
            self::RazorpayXDuplicatePayoutReference => 'RazorpayX duplicate payout reference',
        };
    }
}
