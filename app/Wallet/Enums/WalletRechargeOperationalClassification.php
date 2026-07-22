<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

use App\Models\WalletRecharge;
use Carbon\CarbonInterface;

/**
 * The single, centralized derivation of a recharge attempt's
 * operational meaning for monitoring — Blade/Filament never re-derive
 * this from raw status/timestamp fields themselves.
 *
 * "Stale" here is a monitoring concept, not reconciliation's own "due
 * for a sync pass" concept (WalletRechargeReconciliationService
 * treats every never-synced row as immediately due, by design — see
 * its own docblock). For a human dashboard, a five-second-old attempt
 * calling itself "stale" would be misleading; staleness is measured
 * from the attempt's own age (created_at), not from last_synced_at,
 * though both use the same DUE_AFTER_MINUTES threshold for consistency.
 */
enum WalletRechargeOperationalClassification: string
{
    case AwaitingConfirmation = 'awaiting_confirmation';
    case StaleProviderCreated = 'stale_provider_created';
    case StaleAwaitingConfirmation = 'stale_awaiting_confirmation';
    case ProviderTerminalFailure = 'provider_terminal_failure';
    case CapturedCreditPending = 'captured_credit_pending';
    case CapturedCreditFailed = 'captured_credit_failed';
    case Succeeded = 'succeeded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public static function fromRecharge(WalletRecharge $recharge, CarbonInterface $staleCutoff): self
    {
        $stale = $recharge->created_at !== null && $recharge->created_at->lessThanOrEqualTo($staleCutoff);

        return match ($recharge->status) {
            WalletRechargeStatus::Succeeded => self::Succeeded,
            WalletRechargeStatus::Failed => self::ProviderTerminalFailure,
            WalletRechargeStatus::Cancelled => self::Cancelled,
            WalletRechargeStatus::Expired => self::Expired,
            WalletRechargeStatus::CreditPending => self::CapturedCreditPending,
            WalletRechargeStatus::CreditFailed => self::CapturedCreditFailed,
            WalletRechargeStatus::ProviderCreated => $stale ? self::StaleProviderCreated : self::AwaitingConfirmation,
            WalletRechargeStatus::AwaitingConfirmation => $stale ? self::StaleAwaitingConfirmation : self::AwaitingConfirmation,
            WalletRechargeStatus::Pending => $stale ? self::StaleProviderCreated : self::AwaitingConfirmation,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'Awaiting confirmation',
            self::StaleProviderCreated => 'Stale — provider created',
            self::StaleAwaitingConfirmation => 'Stale — awaiting confirmation',
            self::ProviderTerminalFailure => 'Provider terminal failure',
            self::CapturedCreditPending => 'Captured — credit pending',
            self::CapturedCreditFailed => 'Captured — credit failed',
            self::Succeeded => 'Succeeded',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /** Provider has durably confirmed capture (provider_confirmed_at was set) but the wallet has not yet been credited. */
    public function isCapturedButUncredited(): bool
    {
        return match ($this) {
            self::CapturedCreditPending, self::CapturedCreditFailed => true,
            default => false,
        };
    }

    public function isStale(): bool
    {
        return match ($this) {
            self::StaleProviderCreated, self::StaleAwaitingConfirmation => true,
            default => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::ProviderTerminalFailure, self::CapturedCreditFailed => 'danger',
            self::CapturedCreditPending, self::StaleProviderCreated, self::StaleAwaitingConfirmation => 'warning',
            self::Cancelled, self::Expired => 'gray',
            self::AwaitingConfirmation => 'info',
        };
    }
}
