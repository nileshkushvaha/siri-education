<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Lifecycle of an instructor payout method's RazorpayX Contact→Fund
 * Account provisioning. Never a mapping of any RazorpayX payout status
 * (see PayoutFailureCategory / InstructorPayoutAttemptStatus for that).
 * `*Unknown` states exist because a provisioning call can time out after
 * the provider has already accepted it — those are never auto-retried,
 * only resolved by RazorpayXDestinationReconciliationService using
 * provider-confirmed evidence. Changed bank details never mutate a
 * `Ready` link — a new payout method and a new link are created instead;
 * `Stale` only ever comes from an admin/reconciliation action flagging
 * this link as no longer trustworthy.
 */
enum RazorpayXProviderLinkStatus: string
{
    case Pending = 'pending';
    case ContactProvisioning = 'contact_provisioning';
    case ContactUnknown = 'contact_unknown';
    case ContactReady = 'contact_ready';
    case FundAccountProvisioning = 'fund_account_provisioning';
    case FundAccountUnknown = 'fund_account_unknown';
    case Ready = 'ready';
    case Stale = 'stale';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::ContactProvisioning => 'Provisioning Contact',
            self::ContactUnknown => 'Contact Outcome Unknown',
            self::ContactReady => 'Contact Ready',
            self::FundAccountProvisioning => 'Provisioning Fund Account',
            self::FundAccountUnknown => 'Fund Account Outcome Unknown',
            self::Ready => 'Ready',
            self::Stale => 'Stale',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Pending, self::ContactProvisioning, self::FundAccountProvisioning, self::ContactReady => 'warning',
            self::ContactUnknown, self::FundAccountUnknown, self::Stale => 'danger',
            self::Failed, self::Disabled => 'gray',
        };
    }

    /** A payout may only be initiated against a link in this state. */
    public function isUsableForPayout(): bool
    {
        return $this === self::Ready;
    }

    public function needsReconciliation(): bool
    {
        return match ($this) {
            self::ContactUnknown, self::FundAccountUnknown => true,
            default => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // ContactReady is directly reachable from Pending too: the
            // instructor-level Contact reuse fast path
            // (RazorpayXDestinationProvisioningService::ensureContact())
            // resolves a sibling link's already-known Contact without
            // ever making a network call, so there is no "provisioning
            // in flight" state to pass through.
            self::Pending => [self::ContactProvisioning, self::ContactReady, self::Disabled],
            self::ContactProvisioning => [self::ContactReady, self::ContactUnknown, self::Failed, self::Disabled],
            // ContactProvisioning is reachable again from here too: a
            // refresh() retry that finds no reusable sibling Contact
            // must be able to make a real (new) provisioning attempt,
            // not just resolve via the fast reuse path.
            self::ContactUnknown => [self::ContactProvisioning, self::ContactReady, self::Failed, self::Disabled],
            // Ready is directly reachable from ContactReady too: the
            // Fund Account reuse fast path
            // (RazorpayXDestinationProvisioningService::ensureFundAccount())
            // resolves a sibling link's already-known Fund Account
            // without ever making a network call.
            self::ContactReady => [self::FundAccountProvisioning, self::Ready, self::Disabled],
            self::FundAccountProvisioning => [self::Ready, self::FundAccountUnknown, self::Failed, self::Disabled],
            // FundAccountProvisioning is reachable again from here too,
            // for the same reason ContactUnknown can reach
            // ContactProvisioning — a refresh() retry that finds no
            // reusable sibling Fund Account must be able to make a real
            // provisioning attempt.
            self::FundAccountUnknown => [self::FundAccountProvisioning, self::Ready, self::Failed, self::Disabled],
            self::Ready => [self::Stale, self::Disabled],
            self::Stale => [self::Disabled],
            self::Failed => [self::Disabled],
            self::Disabled => [],
        };
    }
}
